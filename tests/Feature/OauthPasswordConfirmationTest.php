<?php

use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Once;
use Illuminate\Support\ViewErrorBag;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('app.maintenance.driver', 'file');

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

function linkAuthentikIdentityForConfirmation(User $user, string $providerUserId): OauthIdentity
{
    return OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'authentik',
        'issuer' => 'https://authentik.example.com',
        'provider_user_id' => $providerUserId,
        'email' => $user->email,
    ]);
}

describe('confirmation requirement', function () {
    it('skips the password for a user with a password and a linked identity', function () {
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);
        linkAuthentikIdentityForConfirmation($user, 'authentik-id');
        $this->actingAs($user);

        expect($user->requiresPasswordConfirmation())->toBeFalse()
            ->and(shouldSkipPasswordConfirmation())->toBeTrue()
            ->and(verifyPasswordConfirmation(''))->toBeTrue()
            ->and(verifyPasswordConfirmation('wrong-password'))->toBeTrue();
    });

    it('skips the password for a user with a linked identity and no password', function () {
        $user = User::factory()->create();
        $user->forceFill(['password' => null])->save();
        linkAuthentikIdentityForConfirmation($user, 'authentik-id');
        $this->actingAs($user);

        expect($user->requiresPasswordConfirmation())->toBeFalse()
            ->and(shouldSkipPasswordConfirmation())->toBeTrue()
            ->and(verifyPasswordConfirmation(''))->toBeTrue();
    });

    it('requires the correct password for a user without a linked identity', function () {
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);
        $this->actingAs($user);

        expect($user->requiresPasswordConfirmation())->toBeTrue()
            ->and(shouldSkipPasswordConfirmation())->toBeFalse()
            ->and(verifyPasswordConfirmation('wrong-password'))->toBeFalse()
            ->and(verifyPasswordConfirmation(''))->toBeFalse()
            ->and(verifyPasswordConfirmation(['secret-password']))->toBeFalse()
            ->and(verifyPasswordConfirmation('secret-password'))->toBeTrue();
    });

    it('keeps skipping confirmation for a user without a password and without an identity', function () {
        $user = User::factory()->create();
        $user->forceFill(['password' => null])->save();
        $this->actingAs($user);

        expect($user->requiresPasswordConfirmation())->toBeFalse()
            ->and(shouldSkipPasswordConfirmation())->toBeTrue();
    });

    it('skips the password for everyone when two-step confirmation is disabled', function () {
        InstanceSettings::query()->update(['disable_two_step_confirmation' => true]);
        Once::flush();
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);
        $this->actingAs($user);

        expect(shouldSkipPasswordConfirmation())->toBeTrue()
            ->and(verifyPasswordConfirmation('wrong-password'))->toBeTrue();
    });
});

describe('oauth confirmation route', function () {
    it('no longer exists', function () {
        $user = User::factory()->create();
        linkAuthentikIdentityForConfirmation($user, 'authentik-id');

        expect(Route::has('auth.confirm'))->toBeFalse();

        Socialite::shouldReceive('driver')->never();

        // The path falls through to the catch-all route and stays inside Coolify.
        $response = $this->actingAs($user)->get('/auth/authentik/confirm');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toStartWith(url('/'));
    });

    it('keeps the normal login flow and does not mark the session as password confirmed', function () {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        linkAuthentikIdentityForConfirmation($user, 'owner-authentik-id');

        $provider = Mockery::mock();
        $provider->shouldReceive('setConfig')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn((object) [
            'email' => 'owner@example.com',
            'name' => 'Provider User',
            'id' => 'owner-authentik-id',
            'user' => ['email_verified' => true],
        ]);
        Socialite::shouldReceive('driver')->with('authentik')->andReturn($provider);

        $this->get(route('auth.callback', 'authentik'))->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        expect(session('auth.password_confirmed_at'))->toBeNull();
    });
});

describe('confirmation modal', function () {
    function renderConfirmationModal(): string
    {
        view()->share('errors', new ViewErrorBag);

        return Blade::render('<x-modal-confirmation title="Delete" buttonTitle="Delete" submitAction="delete" :actions="[]" confirmationText="x" />');
    }

    it('shows only the typed confirmation for a user with a linked identity', function () {
        $user = User::factory()->create();
        linkAuthentikIdentityForConfirmation($user, 'authentik-id');
        $this->actingAs($user);

        $html = renderConfirmationModal();

        expect($html)->toContain('x-model="userConfirmationText"')
            ->and($html)->toContain('confirmWithText: true')
            ->and($html)->toContain('confirmWithPassword: false')
            ->and($html)->not->toContain('type="password"')
            ->and($html)->not->toContain('Confirm with Google')
            ->and($html)->not->toContain('/auth/authentik/confirm');
    });

    it('shows the password step for a user without an identity', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $html = renderConfirmationModal();

        expect($html)->toContain('confirmWithPassword: true')
            ->and($html)->toContain('type="password"')
            ->and($html)->not->toContain('Confirm with Google');
    });
});

describe('two-factor setup', function () {
    beforeEach(function () {
        $this->team = Team::factory()->create(['show_boarding' => false]);
    });

    it('asks a user with a linked identity and no password to set a password before enabling 2fa', function () {
        $user = User::factory()->create();
        $user->forceFill(['password' => null])->save();
        $this->team->members()->attach($user, ['role' => 'owner']);
        linkAuthentikIdentityForConfirmation($user, 'authentik-id');

        $this->actingAs($user)
            ->withSession(['currentTeam' => $this->team])
            ->post(route('two-factor.enable'))
            ->assertRedirect(route('password.confirm'));

        expect($user->fresh()->two_factor_secret)->toBeNull();
    });

    it('still asks a password user without a linked identity to confirm the password', function () {
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);
        $this->team->members()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['currentTeam' => $this->team])
            ->post(route('two-factor.enable'))
            ->assertRedirect(route('password.confirm'));

        expect($user->fresh()->two_factor_secret)->toBeNull();
    });
});
