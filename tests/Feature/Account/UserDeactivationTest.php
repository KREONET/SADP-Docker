<?php

use App\Actions\User\DeactivateUserAccount;
use App\Auth\Oidc\OidcUser;
use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OauthLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_registration_enabled' => false]);
    Once::flush();
    Cache::flush();
    Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
});

function deactivatedOidcLogin(User $user): User
{
    $setting = OauthSetting::create([
        'provider' => 'oidc',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'base_url' => 'https://idp.example.com',
        'allow_registration' => false,
    ]);
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'oidc',
        'issuer' => 'https://idp.example.com',
        'provider_user_id' => 'paused-subject',
        'email' => $user->email,
    ]);
    $oidcUser = (new OidcUser)->setRaw([
        'iss' => 'https://idp.example.com',
        'sub' => 'paused-subject',
        'email' => $user->email,
        'email_verified' => true,
    ])->map(['id' => 'paused-subject', 'name' => $user->name, 'email' => $user->email]);

    return app(OauthLoginService::class)->login('oidc', $oidcUser, $setting);
}

it('keeps the account and its teams but signs it out everywhere', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();
    session(['currentTeam' => $team]);
    $token = $user->createToken('cli', ['read']);
    DB::table('sessions')->insert(['id' => 'paused-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    $rememberToken = $user->remember_token;

    DeactivateUserAccount::execute($user, 'scim');

    $user->refresh();
    expect($user->isDeactivated())->toBeTrue()
        ->and(Team::find($team->id))->not->toBeNull()
        ->and($user->remember_token)->not->toBe($rememberToken)
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->exists())->toBeFalse();
});

it('refuses to deactivate the root account', function () {
    $root = User::factory()->create(['id' => 0]);

    expect(fn () => DeactivateUserAccount::execute($root, 'scim'))->toThrow(RuntimeException::class);
});

it('blocks password sign-in for a deactivated account', function () {
    $user = User::factory()->create(['email' => 'paused@example.com']);
    DeactivateUserAccount::execute($user, 'scim');

    $this->post('/login', ['email' => 'paused@example.com', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('blocks oidc sign-in for a deactivated account', function () {
    $user = User::factory()->create(['email' => 'paused@example.com']);
    DeactivateUserAccount::execute($user, 'scim');

    expect(fn () => deactivatedOidcLogin($user->fresh()))
        ->toThrow(HttpException::class, 'This account has been deactivated.');
    $this->assertGuest();
});

it('signs out a session that is still open when the account is deactivated', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    session(['currentTeam' => $user->teams()->first()]);
    $user->forceFill(['deactivated_at' => now()])->save();

    $this->get('/projects')->assertRedirect(route('login'));

    $this->assertGuest();
});

it('signs in again after the account is reactivated', function () {
    $user = User::factory()->create(['email' => 'back@example.com']);
    DeactivateUserAccount::execute($user, 'scim');
    DeactivateUserAccount::reactivate($user->fresh(), 'scim');

    $this->post('/login', ['email' => 'back@example.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($user->fresh());
});
