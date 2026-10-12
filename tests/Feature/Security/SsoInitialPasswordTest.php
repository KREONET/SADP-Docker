<?php

use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const SSO_INITIAL_PASSWORD = 'Str0ng-Passw0rd!2026';

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create(['show_boarding' => false]);

    // SSO accounts are created with a random password nobody knows.
    $this->ssoUser = User::factory()->create(['password' => Hash::make(Str::random(64))]);
    OauthIdentity::create([
        'user_id' => $this->ssoUser->id,
        'provider' => 'oidc',
        'issuer' => 'https://idp.example.com',
        'provider_user_id' => 'idp-user-1',
        'email' => $this->ssoUser->email,
    ]);
    $this->team->members()->attach($this->ssoUser->id, ['role' => 'owner']);
});

function actAsRecentlySignedIn(User $user): void
{
    test()->actingAs($user);
    session(['currentTeam' => test()->team]);
}

test('sso users are offered to set a password on the confirmation page', function () {
    actAsRecentlySignedIn($this->ssoUser);

    $this->from('/profile')->post('/user/two-factor-authentication')->assertRedirect(route('password.confirm'));

    $this->get(route('password.confirm'))
        ->assertOk()
        ->assertSee('Set password and continue')
        ->assertSee('I already know my password');
});

test('an sso user sets a password and can then enable two-factor authentication', function () {
    actAsRecentlySignedIn($this->ssoUser);

    $this->from(route('password.confirm'))
        ->post(route('password.initial.store'), [
            'password' => SSO_INITIAL_PASSWORD,
            'password_confirmation' => SSO_INITIAL_PASSWORD,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile'))
        ->assertSessionHas('status', 'initial-password-set');

    $user = $this->ssoUser->fresh();
    expect(Hash::check(SSO_INITIAL_PASSWORD, $user->password))->toBeTrue()
        ->and($user->local_password_set_at)->not->toBeNull()
        ->and($user->needsInitialPassword())->toBeFalse();

    // The new password counts as confirmed, so the 2FA setup continues without another prompt.
    $this->from('/profile')->post('/user/two-factor-authentication')
        ->assertRedirect('/profile')
        ->assertSessionHas('status', 'two-factor-authentication-enabled');
    expect($this->ssoUser->fresh()->two_factor_secret)->not->toBeNull();
});

test('the new password must be confirmed', function () {
    actAsRecentlySignedIn($this->ssoUser);

    $this->from(route('password.confirm'))
        ->post(route('password.initial.store'), [
            'password' => SSO_INITIAL_PASSWORD,
            'password_confirmation' => 'something-else',
        ])
        ->assertSessionHasErrorsIn('initialPassword', 'password');

    expect($this->ssoUser->fresh()->needsInitialPassword())->toBeTrue();
});

test('users with a known password keep the normal confirmation and cannot use the first-password form', function (string $who) {
    $user = $this->ssoUser;
    if ($who === 'password user') {
        $user = User::factory()->create();
        $this->team->members()->attach($user->id, ['role' => 'member']);
    }
    if ($who === 'sso user with own password') {
        $user->forceFill(['local_password_set_at' => now()])->save();
    }
    actAsRecentlySignedIn($user);

    $this->get(route('password.confirm'))
        ->assertOk()
        ->assertDontSee('Set password and continue');

    $this->post(route('password.initial.store'), [
        'password' => SSO_INITIAL_PASSWORD,
        'password_confirmation' => SSO_INITIAL_PASSWORD,
    ])->assertForbidden();
})->with(['password user', 'sso user with own password']);
