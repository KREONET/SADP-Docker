<?php

use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\OauthSetting;
use App\Models\User;
use App\Services\Auth\OauthLoginService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Once;
use Laravel\Fortify\Features;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Authentik\Provider as AuthentikProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate([
        'id' => 0,
        'is_registration_enabled' => false,
    ]);

    Once::flush();

    OauthSetting::create([
        'provider' => 'authentik',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://coolify.example.com/auth/authentik/callback',
        'base_url' => 'https://authentik.example.com',
        'enabled' => true,
    ]);
});

function authentikSetting(): OauthSetting
{
    return OauthSetting::where('provider', 'authentik')->firstOrFail();
}

/**
 * Makes the Authentik Socialite driver return the given user on the callback.
 */
function fakeAuthentikCallbackUser(object $oauthUser): void
{
    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn($oauthUser);

    Socialite::shouldReceive('driver')->once()->with('authentik')->andReturn($provider);
}

function mapAuthentikPayload(array $payload): object
{
    $provider = (new ReflectionClass(AuthentikProvider::class))->newInstanceWithoutConstructor();

    return (new ReflectionMethod(AuthentikProvider::class, 'mapUserToObject'))->invoke($provider, $payload);
}

it('logs in an existing user when the oauth provider returns a mixed-case email', function () {
    config()->set('app.maintenance.driver', 'file');

    $user = User::factory()->create([
        'email' => 'username@example.edu',
    ]);

    fakeAuthentikCallbackUser((object) [
        'email' => 'UserName@example.edu',
        'name' => 'Example User',
        'id' => 'authentik-user-id',
        'user' => ['email_verified' => true],
    ]);

    $response = $this->get(route('auth.callback', 'authentik'));

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
    expect(OauthIdentity::where([
        'user_id' => $user->id,
        'provider' => 'authentik',
        'provider_user_id' => 'authentik-user-id',
    ])->exists())->toBeTrue();
});

it('never moves an existing oauth identity when the provider email changes', function () {
    config()->set('app.maintenance.driver', 'file');

    $identityOwner = User::factory()->create(['email' => 'old@example.com']);
    $otherUser = User::factory()->create(['email' => 'new@example.com']);
    $identity = OauthIdentity::create([
        'user_id' => $identityOwner->id,
        'provider' => 'authentik',
        'issuer' => 'authentik',
        'provider_user_id' => 'authentik-user-id',
        'email' => 'old@example.com',
    ]);

    fakeAuthentikCallbackUser((object) [
        'email' => 'new@example.com',
        'name' => 'Example User',
        'id' => 'authentik-user-id',
        'user' => ['email_verified' => true],
    ]);

    $this->get(route('auth.callback', 'authentik'))->assertRedirect('/');

    $this->assertAuthenticatedAs($identityOwner);
    expect($identity->refresh()->user_id)->toBe($identityOwner->id)
        ->and($identity->email)->toBe('new@example.com')
        ->and($identity->user_id)->not->toBe($otherUser->id);
});

it('keeps an existing oauth identity working without new email verification evidence', function () {
    $user = User::factory()->create(['email' => 'existing@example.com']);
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'authentik',
        'issuer' => 'authentik',
        'provider_user_id' => 'existing-authentik-id',
        'email' => $user->email,
    ]);

    $resolvedUser = app(OauthLoginService::class)->login('authentik', (object) [
        'email' => 'changed@example.com',
        'name' => 'Existing Authentik User',
        'id' => 'existing-authentik-id',
        'user' => ['email_verified' => false],
    ], authentikSetting());

    expect($resolvedUser->is($user))->toBeTrue();
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('oauth_identities', [
        'user_id' => $user->id,
        'provider' => 'authentik',
        'provider_user_id' => 'existing-authentik-id',
        'email' => 'changed@example.com',
    ]);
});

it('continues oauth login when another request creates the identity first', function () {
    $user = User::factory()->create(['email' => 'race@example.com']);
    $eventName = 'eloquent.creating: '.OauthIdentity::class;

    Event::listen($eventName, function (OauthIdentity $identity): void {
        $attributes = $identity->getAttributes();

        DB::afterRollBack(fn () => DB::table('oauth_identities')->insert($attributes));

        throw new UniqueConstraintViolationException(
            DB::getDefaultConnection(),
            'insert into oauth_identities',
            [],
            new PDOException('duplicate identity'),
        );
    });

    try {
        $resolvedUser = app(OauthLoginService::class)->login('authentik', (object) [
            'email' => 'race@example.com',
            'name' => 'Race User',
            'id' => 'authentik-race-id',
            'user' => ['email_verified' => true],
        ], authentikSetting());
    } finally {
        Event::forget($eventName);
    }

    expect($resolvedUser->is($user))->toBeTrue()
        ->and(OauthIdentity::where('provider_user_id', 'authentik-race-id')->count())->toBe(1);
    $this->assertAuthenticatedAs($user);
});

it('requires a strict boolean email verification claim', function (mixed $verified) {
    $existingUser = User::factory()->create(['email' => 'existing@example.com']);

    expect(fn () => app(OauthLoginService::class)->login('authentik', (object) [
        'email' => 'existing@example.com',
        'name' => 'Authentik User',
        'id' => 'authentik-user-id',
        'user' => ['email_verified' => $verified],
    ], authentikSetting()))->toThrow(HttpException::class);

    $this->assertGuest();
    expect(OauthIdentity::where('user_id', $existingUser->id)->exists())->toBeFalse();
})->with([
    'unverified' => false,
    'string true' => 'true',
    'integer true' => 1,
]);

it('links an existing account on the first login after upgrade from the real Authentik payload', function () {
    $user = User::factory()->create(['email' => 'existing@example.com']);

    $resolvedUser = app(OauthLoginService::class)->login('authentik', mapAuthentikPayload([
        'sub' => 'f4d0a2c1b9e8',
        'name' => 'Existing User',
        'preferred_username' => 'existing',
        'email' => 'existing@example.com',
        'email_verified' => true,
    ]), authentikSetting());

    expect($resolvedUser->is($user))->toBeTrue()
        ->and(OauthIdentity::where(['user_id' => $user->id, 'provider' => 'authentik'])->exists())->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

it('marks only users that exist at upgrade time as created before OAuth identities', function () {
    $existingUser = User::factory()->create();
    $migration = require database_path('migrations/2026_09_29_200325_add_created_before_oauth_identities_to_users_table.php');
    $migration->down();
    $migration->up();

    $newUser = User::factory()->create();

    expect($existingUser->refresh()->created_before_oauth_identities)->toBeTrue()
        ->and($newUser->refresh()->created_before_oauth_identities)->toBeFalse();
});

it('links a user from before the upgrade without an email verification claim', function (?string $password) {
    $user = User::factory()->create([
        'email' => 'legacy@example.com',
        'password' => $password,
        'created_before_oauth_identities' => true,
    ]);

    $resolvedUser = app(OauthLoginService::class)->login('authentik', (object) [
        'email' => 'legacy@example.com',
        'name' => 'Legacy User',
        'id' => 'legacy-provider-id',
        'user' => ['email_verified' => false],
    ], authentikSetting());

    expect($resolvedUser->is($user))->toBeTrue()
        ->and(OauthIdentity::where(['user_id' => $user->id, 'provider' => 'authentik'])->exists())->toBeTrue()
        ->and($user->refresh()->created_before_oauth_identities)->toBeFalse();
    $this->assertAuthenticatedAs($user);
})->with([
    'authentik default email scope, user created by OAuth' => [null],
    'authentik default email scope, user with a password' => ['password'],
]);

it('does not link a second provider to a user from before the upgrade', function () {
    $user = User::factory()->create([
        'email' => 'legacy@example.com',
        'password' => null,
        'created_before_oauth_identities' => true,
    ]);
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'oidc',
        'issuer' => 'https://idp.example.com',
        'provider_user_id' => 'oidc-legacy-id',
        'email' => $user->email,
    ]);

    expect(fn () => app(OauthLoginService::class)->login('authentik', (object) [
        'email' => 'legacy@example.com',
        'name' => 'Attacker',
        'id' => 'authentik-attacker-id',
        'user' => ['email_verified' => false],
    ], authentikSetting()))->toThrow(HttpException::class, 'OAuth identity cannot be linked to this account');

    $this->assertGuest();
    expect(OauthIdentity::count())->toBe(1);
});

it('does not link an existing account when the Authentik payload does not verify the email', function () {
    User::factory()->create(['email' => 'existing@example.com']);

    expect(fn () => app(OauthLoginService::class)->login('authentik', mapAuthentikPayload([
        'sub' => 'f4d0a2c1b9e8',
        'name' => 'Existing User',
        'email' => 'existing@example.com',
        'email_verified' => false,
    ]), authentikSetting()))->toThrow(HttpException::class, 'OAuth provider did not verify the email address');

    $this->assertGuest();
    expect(OauthIdentity::count())->toBe(0);
});

it('registers a new user from a verified provider identity', function () {
    InstanceSettings::findOrFail(0)->update(['is_registration_enabled' => true]);

    $user = app(OauthLoginService::class)->login('authentik', (object) [
        'email' => 'verified@example.com',
        'name' => 'Verified User',
        'id' => 'verified-authentik-id',
        'user' => ['email_verified' => true],
    ], authentikSetting());

    expect($user->email)->toBe('verified@example.com');
    expect($user->email_verified_at)->toBeNull();
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('oauth_identities', [
        'user_id' => $user->id,
        'provider' => 'authentik',
        'provider_user_id' => 'verified-authentik-id',
    ]);
});

it('does not register a new user through a non-OIDC provider when registration is disabled', function () {
    // Upgraded installs have allow_registration = true on every provider row (column default).
    authentikSetting()->update(['allow_registration' => true]);

    expect(fn () => app(OauthLoginService::class)->login('authentik', (object) [
        'email' => 'stranger@example.com',
        'name' => 'Stranger',
        'id' => 'stranger-id',
        'user' => ['email_verified' => true],
    ], authentikSetting()))->toThrow(HttpException::class, 'Registration is disabled');

    $this->assertGuest();
    expect(User::count())->toBe(0)
        ->and(OauthIdentity::count())->toBe(0);
});

it('registers a new user through a non-OIDC provider when registration is enabled', function () {
    InstanceSettings::findOrFail(0)->update(['is_registration_enabled' => true]);
    authentikSetting()->update(['allow_registration' => false]);

    $user = app(OauthLoginService::class)->login('authentik', (object) [
        'email' => 'new-user@example.com',
        'name' => 'New User',
        'id' => 'authentik-new-user-id',
        'user' => ['email_verified' => true],
    ], authentikSetting());

    expect($user->email)->toBe('new-user@example.com');
    $this->assertAuthenticatedAs($user);
});

it('does not link another provider identity to an account by shared email', function () {
    $user = User::factory()->create(['email' => 'shared@example.com']);
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'oidc',
        'issuer' => 'https://idp.example.com',
        'provider_user_id' => 'oidc-user-id',
        'email' => 'shared@example.com',
    ]);

    expect(fn () => app(OauthLoginService::class)->login('authentik', (object) [
        'email' => 'shared@example.com',
        'name' => 'Other Provider User',
        'id' => 'authentik-user-id',
        'user' => ['email_verified' => true],
    ], authentikSetting()))->toThrow(HttpException::class);

    $this->assertGuest();
    expect(OauthIdentity::count())->toBe(1);
});

it('sends an OAuth user with confirmed two factor authentication to the Fortify challenge', function () {
    config()->set('fortify.features', [Features::twoFactorAuthentication(['confirm' => true])]);
    $user = User::factory()->create([
        'email' => 'two-factor@example.com',
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ]);
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'authentik',
        'issuer' => 'authentik',
        'provider_user_id' => 'two-factor-authentik-id',
        'email' => $user->email,
    ]);

    fakeAuthentikCallbackUser((object) [
        'email' => $user->email,
        'name' => $user->name,
        'id' => 'two-factor-authentik-id',
        'user' => ['email_verified' => true],
    ]);

    $this->get(route('auth.callback', 'authentik'))
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
});

it('completes OAuth login without a challenge when two factor authentication is not enabled for the user', function () {
    $user = User::factory()->create(['email' => 'without-two-factor@example.com']);
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'authentik',
        'issuer' => 'authentik',
        'provider_user_id' => 'plain-authentik-id',
        'email' => $user->email,
    ]);

    fakeAuthentikCallbackUser((object) [
        'email' => $user->email,
        'name' => $user->name,
        'id' => 'plain-authentik-id',
        'user' => ['email_verified' => true],
    ]);

    $this->get(route('auth.callback', 'authentik'))->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

it('rejects redirect requests for a disabled provider', function () {
    $this->withoutVite();
    authentikSetting()->update(['enabled' => false]);

    $this->get(route('auth.redirect', 'authentik'))->assertForbidden();
    $this->assertGuest();
});

it('rejects callback requests for a disabled provider', function () {
    authentikSetting()->update(['enabled' => false]);

    $this->from('/login')->get(route('auth.callback', 'authentik'))->assertRedirect('/login');
    $this->assertGuest();
});

it('rejects oauth logins when the provider does not return an email address', function (?string $providerEmail) {
    config()->set('app.maintenance.driver', 'file');
    InstanceSettings::findOrFail(0)->update(['is_registration_enabled' => true]);

    fakeAuthentikCallbackUser((object) [
        'email' => $providerEmail,
        'name' => 'Example User',
        'id' => 'authentik-user-id',
    ]);

    $response = $this->from('/login')->get(route('auth.callback', 'authentik'));

    $response->assertRedirect('/login');
    expect(User::count())->toBe(0);
})->with([
    'null email' => [null],
    'blank email' => ['   '],
    'malformed email' => ['not-an-email'],
    'missing domain' => ['user@'],
]);

it('rejects oauth logins when the provider does not return a valid user id', function (mixed $invalidId) {
    $oauthUser = (object) [
        'email' => 'user@example.edu',
        'name' => 'Example User',
    ];

    if ($invalidId !== 'missing') {
        $oauthUser->id = $invalidId;
    }

    try {
        app(OauthLoginService::class)->login('authentik', $oauthUser, authentikSetting());
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403)
            ->and(OauthIdentity::count())->toBe(0)
            ->and(User::count())->toBe(0);

        return;
    }

    $this->fail('Expected an invalid OAuth provider user ID to be rejected.');
})->with([
    'null id' => [null],
    'missing id' => ['missing'],
    'blank id' => ['   '],
    'non-scalar id' => [[]],
    'true id' => [true],
    'false id' => [false],
    'float id' => [1.0],
]);
