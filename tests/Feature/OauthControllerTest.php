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
        'issuer' => 'https://authentik.example.com',
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
        'issuer' => 'https://authentik.example.com',
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

function rerunCreatedBeforeOauthIdentitiesMigration(): void
{
    $migration = require database_path('migrations/2026_09_29_200325_add_created_before_oauth_identities_to_users_table.php');
    $migration->down();
    $migration->up();
}

/**
 * Brings the users into the state after an upgrade from v4.3.23.
 */
function upgradeUsersToOauthIdentities(): void
{
    rerunCreatedBeforeOauthIdentitiesMigration();
}

it('marks only password-less users that exist at upgrade time as created before OAuth identities', function () {
    $rootUser = User::factory()->create(['id' => 0]);
    $passwordUser = User::factory()->create();
    $oauthCreatedUser = User::factory()->create(['password' => null]);

    rerunCreatedBeforeOauthIdentitiesMigration();

    $newUser = User::factory()->create(['password' => null]);

    expect($oauthCreatedUser->refresh()->created_before_oauth_identities)->toBeTrue()
        ->and($rootUser->refresh()->created_before_oauth_identities)->toBeFalse()
        ->and($passwordUser->refresh()->created_before_oauth_identities)->toBeFalse()
        ->and($newUser->refresh()->created_before_oauth_identities)->toBeFalse();
});

it('does not link an unverified provider email to a password user after the upgrade', function (string $provider, array $rawClaims) {
    $user = User::factory()->create(['id' => 0, 'email' => 'root@example.com']);
    $setting = OauthSetting::updateOrCreate(['provider' => $provider], [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'base_url' => 'https://auth.example.com',
        'enabled' => true,
    ]);
    upgradeUsersToOauthIdentities();

    expect(fn () => app(OauthLoginService::class)->login($provider, (object) [
        'email' => 'root@example.com',
        'name' => 'Attacker',
        'id' => 'attacker-provider-id',
        'user' => $rawClaims,
    ], $setting))->toThrow(HttpException::class, 'OAuth provider did not verify the email address');

    $this->assertGuest();
    expect(OauthIdentity::count())->toBe(0)
        ->and($user->refresh()->created_before_oauth_identities)->toBeFalse();
})->with([
    'discord unverified' => ['discord', ['verified' => false]],
    'authentik default email scope' => ['authentik', ['email_verified' => false]],
]);

it('links a verified provider email to a password user after the upgrade', function (string $provider, array $rawClaims) {
    $user = User::factory()->create(['email' => 'member@example.com']);
    $setting = OauthSetting::updateOrCreate(['provider' => $provider], [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'enabled' => true,
    ]);
    upgradeUsersToOauthIdentities();

    $resolvedUser = app(OauthLoginService::class)->login($provider, (object) [
        'email' => 'member@example.com',
        'name' => 'Member',
        'id' => 'member-provider-id',
        'user' => $rawClaims,
    ], $setting);

    expect($resolvedUser->is($user))->toBeTrue()
        ->and(OauthIdentity::where(['user_id' => $user->id, 'provider' => $provider])->exists())->toBeTrue();
    $this->assertAuthenticatedAs($user);
})->with([
    'github' => ['github', []],
    'google verified' => ['google', ['email_verified' => true, 'hd' => 'example.com']],
    'discord verified' => ['discord', ['verified' => true]],
]);

it('links a password-less user from before the upgrade without an email verification claim', function () {
    $user = User::factory()->create(['email' => 'legacy@example.com', 'password' => null]);
    $setting = OauthSetting::create([
        'provider' => 'discord',
        'client_id' => 'discord-client-id',
        'client_secret' => 'discord-client-secret',
        'enabled' => true,
    ]);
    upgradeUsersToOauthIdentities();

    $resolvedUser = app(OauthLoginService::class)->login('discord', (object) [
        'email' => 'legacy@example.com',
        'name' => 'Legacy User',
        'id' => 'legacy-discord-id',
        'user' => ['verified' => false],
    ], $setting);

    expect($resolvedUser->is($user))->toBeTrue()
        ->and($user->refresh()->created_before_oauth_identities)->toBeFalse();
    $this->assertAuthenticatedAs($user);
});

it('links a user from before the upgrade without an email verification claim', function (string $provider, array $rawClaims, ?string $password) {
    $user = User::factory()->create([
        'email' => 'legacy@example.com',
        'password' => $password,
        'created_before_oauth_identities' => true,
    ]);
    $setting = OauthSetting::updateOrCreate(['provider' => $provider], [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'base_url' => 'https://auth.example.com',
        'enabled' => true,
    ]);

    $resolvedUser = app(OauthLoginService::class)->login($provider, (object) [
        'email' => 'legacy@example.com',
        'name' => 'Legacy User',
        'id' => 'legacy-provider-id',
        'user' => $rawClaims,
    ], $setting);

    expect($resolvedUser->is($user))->toBeTrue()
        ->and(OauthIdentity::where(['user_id' => $user->id, 'provider' => $provider])->exists())->toBeTrue()
        ->and($user->refresh()->created_before_oauth_identities)->toBeFalse();
    $this->assertAuthenticatedAs($user);
})->with([
    'discord unverified, user created by OAuth' => ['discord', ['verified' => false], null],
    'authentik default email scope, user created by OAuth' => ['authentik', ['email_verified' => false], null],
]);

it('does not link an unverified provider email to a flagged user who has a password', function (string $provider, array $rawClaims) {
    // A pre-upgrade OAuth user who set a password (for example through a password
    // reset) before the first OAuth login, or a password user on an instance that
    // ran the first version of the migration.
    $user = User::factory()->create([
        'email' => 'legacy@example.com',
        'password' => 'password',
        'created_before_oauth_identities' => true,
    ]);
    $setting = OauthSetting::updateOrCreate(['provider' => $provider], [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'base_url' => 'https://auth.example.com',
        'enabled' => true,
    ]);

    expect(fn () => app(OauthLoginService::class)->login($provider, (object) [
        'email' => 'legacy@example.com',
        'name' => 'Attacker',
        'id' => 'attacker-provider-id',
        'user' => $rawClaims,
    ], $setting))->toThrow(HttpException::class, 'OAuth provider did not verify the email address');

    expect(OauthIdentity::count())->toBe(0);
    $this->assertGuest();
})->with([
    'discord unverified' => ['discord', ['verified' => false]],
    'authentik default email scope' => ['authentik', ['email_verified' => false]],
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
        'issuer' => 'https://authentik.example.com',
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
        'issuer' => 'https://authentik.example.com',
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

it('sends the configured instance callback URL without saving it on the provider', function () {
    InstanceSettings::query()->whereKey(0)->update(['fqdn' => 'https://coolify.example.com']);
    Once::flush();
    $setting = authentikSetting();
    $setting->update(['redirect_uri' => null]);

    $response = $this->get('http://app.example.test/auth/authentik/redirect')->assertRedirect();
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['redirect_uri'])->toBe('https://coolify.example.com/auth/authentik/callback')
        ->and($setting->fresh()->redirect_uri)->toBeNull();
});

describe('callback error messages', function () {
    beforeEach(function () {
        config()->set('app.maintenance.driver', 'file');
    });

    it('tells a user that the account already uses another sign-in method', function () {
        $user = User::factory()->create(['email' => 'linked@example.com']);
        OauthIdentity::create([
            'user_id' => $user->id,
            'provider' => 'github',
            'issuer' => 'github',
            'provider_user_id' => 'github-user-id',
            'email' => 'linked@example.com',
        ]);
        fakeAuthentikCallbackUser((object) [
            'email' => 'linked@example.com',
            'name' => 'Linked User',
            'id' => 'authentik-user-id',
            'user' => ['email_verified' => true],
        ]);

        $this->from('/login')->get(route('auth.callback', 'authentik'))
            ->assertRedirect('/login');
        expect(session('errors')->first())->toBe(__('auth.failed.oauth_already_linked'));
        $this->assertGuest();
    });

    it('tells a user that the provider did not verify the email before it reveals a linked account', function () {
        $user = User::factory()->create(['email' => 'linked@example.com']);
        OauthIdentity::create([
            'user_id' => $user->id,
            'provider' => 'github',
            'issuer' => 'github',
            'provider_user_id' => 'github-user-id',
            'email' => 'linked@example.com',
        ]);
        fakeAuthentikCallbackUser((object) [
            'email' => 'linked@example.com',
            'name' => 'Unverified User',
            'id' => 'authentik-user-id',
            'user' => ['email_verified' => false],
        ]);

        $this->from('/login')->get(route('auth.callback', 'authentik'))
            ->assertRedirect('/login');
        expect(session('errors')->first())->toBe(__('auth.failed.oauth_email_unverified'));
        $this->assertGuest();
    });

    it('tells a new user that registration is disabled', function () {
        fakeAuthentikCallbackUser((object) [
            'email' => 'new@example.com',
            'name' => 'New User',
            'id' => 'authentik-user-id',
            'user' => ['email_verified' => true],
        ]);

        $this->from('/login')->get(route('auth.callback', 'authentik'))
            ->assertRedirect('/login');
        expect(session('errors')->first())->toBe(__('auth.registration_disabled'));
        $this->assertGuest();
    });

    it('shows a generic OAuth message instead of the password message for other denied logins', function () {
        fakeAuthentikCallbackUser((object) [
            'name' => 'Example User',
            'id' => 'authentik-user-id',
        ]);

        $this->from('/login')->get(route('auth.callback', 'authentik'))
            ->assertRedirect('/login');
        expect(session('errors')->first())->toBe(__('auth.failed.oauth'))
            ->not->toBe(__('auth.failed'));
        $this->assertGuest();
    });
});

it('matches the Google hosted domain without case or spaces and accepts any Workspace for a wildcard', function (string $tenant, ?string $hostedDomain, bool $allowed) {
    $user = User::factory()->create(['email' => 'user@example.com']);
    $setting = new OauthSetting(['provider' => 'google', 'tenant' => $tenant]);
    $claims = array_filter(['email_verified' => true, 'hd' => $hostedDomain], fn ($value) => $value !== null);

    $login = fn () => app(OauthLoginService::class)->login('google', (object) [
        'email' => $user->email,
        'name' => 'Workspace User',
        'id' => 'google-workspace-id',
        'user' => $claims,
    ], $setting);

    if ($allowed) {
        expect($login()->is($user))->toBeTrue();
    } else {
        expect($login)->toThrow(HttpException::class, 'Google account is not in the configured Workspace');
        $this->assertGuest();
    }
})->with([
    'mixed-case tenant' => ['Example.com', 'example.com', true],
    'tenant with spaces' => [' example.com ', 'example.com', true],
    'mixed-case hd claim' => ['example.com', 'EXAMPLE.com', true],
    'wildcard with a Workspace account' => ['*', 'any-company.example', true],
    'wildcard with a personal account' => ['*', null, false],
    'other domain' => ['example.com', 'example.org', false],
    'personal account' => ['example.com', null, false],
]);

it('allows OAuth user creation through global registration or the OIDC user creation setting', function (string $provider, bool $isRegistrationEnabled, bool $allowRegistration, bool $expected) {
    InstanceSettings::query()->whereKey(0)->update(['is_registration_enabled' => $isRegistrationEnabled]);
    Once::flush();

    $setting = new OauthSetting(['provider' => $provider, 'allow_registration' => $allowRegistration]);

    expect($setting->allowsUserCreation())->toBe($expected);
})->with([
    'github, registration on' => ['github', true, false, true],
    'github, registration off' => ['github', false, true, false],
    'oidc, registration off, oidc user creation on' => ['oidc', false, true, true],
    'oidc, registration off, oidc user creation off' => ['oidc', false, false, false],
    'oidc, registration on, oidc user creation off' => ['oidc', true, false, true],
]);

it('registers a new user from an unverified provider email when registration is enabled', function (string $provider, array $rawClaims) {
    InstanceSettings::findOrFail(0)->update(['is_registration_enabled' => true]);
    $setting = OauthSetting::updateOrCreate(['provider' => $provider], [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'base_url' => 'https://auth.example.com',
        'enabled' => true,
    ]);

    $user = app(OauthLoginService::class)->login($provider, (object) [
        'email' => 'new-user@example.com',
        'name' => 'New User',
        'id' => 'new-provider-id',
        'user' => $rawClaims,
    ], $setting);

    expect($user->email)->toBe('new-user@example.com')
        ->and(OauthIdentity::where(['user_id' => $user->id, 'provider' => $provider])->exists())->toBeTrue();
    $this->assertAuthenticatedAs($user);
})->with([
    'authentik default email scope' => ['authentik', ['email_verified' => false]],
    'discord unverified' => ['discord', ['verified' => false]],
    'gitlab unconfirmed' => ['gitlab', ['confirmed_at' => null]],
]);

it('tells a new user with an unverified provider email that registration is disabled', function () {
    $setting = OauthSetting::updateOrCreate(['provider' => 'authentik'], [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'base_url' => 'https://auth.example.com',
        'enabled' => true,
    ]);

    expect(fn () => app(OauthLoginService::class)->login('authentik', (object) [
        'email' => 'new-user@example.com',
        'name' => 'New User',
        'id' => 'new-provider-id',
        'user' => ['email_verified' => false],
    ], $setting))->toThrow(HttpException::class, 'Registration is disabled');

    expect(User::count())->toBe(0);
    $this->assertGuest();
});
