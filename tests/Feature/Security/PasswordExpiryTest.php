<?php

use App\Http\Middleware\EnforcePasswordExpiry;
use App\Livewire\ForcePasswordReset;
use App\Livewire\Profile\Index as ProfileIndex;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Services\Auth\OauthLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('security.password.expires_after_days', 90);
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    Cache::flush();
});

function expiredPasswordUser(): User
{
    $user = User::factory()->create(['password' => Hash::make('Old!Passw0rd')]);
    $user->forceFill(['password_changed_at' => now()->subDays(91)])->saveQuietly();

    return $user->fresh();
}

function signInWithTeam(User $user, array $session = []): void
{
    test()->actingAs($user)->withSession(['currentTeam' => $user->teams()->first()] + $session);
}

it('redirects a user with an expired password to the forced change screen', function () {
    signInWithTeam(expiredPasswordUser());

    $this->get('/projects')->assertRedirect(route('auth.force-password-reset'));
});

it('leaves users with a current password alone', function () {
    $user = User::factory()->create();
    signInWithTeam($user);

    expect($user->password_changed_at)->not->toBeNull();
    $response = $this->get('/projects');
    expect($response->headers->get('Location'))->not->toBe(route('auth.force-password-reset'));
});

it('exempts sessions that signed in through oauth or sso', function () {
    signInWithTeam(expiredPasswordUser(), [OauthLoginService::SSO_SESSION_KEY => 'oidc']);

    $response = $this->get('/projects');
    expect($response->headers->get('Location'))->not->toBe(route('auth.force-password-reset'));
});

it('does nothing when expiry is disabled', function () {
    config()->set('security.password.expires_after_days', 0);
    signInWithTeam(expiredPasswordUser());

    $response = $this->get('/projects');
    expect($response->headers->get('Location'))->not->toBe(route('auth.force-password-reset'));
});

it('keeps the change screen, logout and livewire endpoints reachable', function (string $path) {
    $user = expiredPasswordUser();
    $request = Request::create("/{$path}", 'GET');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session.store'));

    $response = (new EnforcePasswordExpiry)->handle($request, fn () => new Response('next'));

    expect($response->getContent())->toBe('next');
})->with(['force-password-reset', 'logout', 'livewire/update', 'two-factor-challenge']);

it('explains the expiry on the change screen', function () {
    $user = expiredPasswordUser();
    signInWithTeam($user);

    $this->get(route('auth.force-password-reset'))
        ->assertOk()
        ->assertSee(__('Your password has expired'));
});

it('lets an expired user set a new password and continue', function () {
    $user = expiredPasswordUser();
    $this->actingAs($user);

    Livewire::test(ForcePasswordReset::class)
        ->set('password', 'New!Passw0rd')
        ->set('password_confirmation', 'New!Passw0rd')
        ->call('submit')
        ->assertRedirect(route('dashboard'));

    $user->refresh();
    expect(Hash::check('New!Passw0rd', $user->password))->toBeTrue()
        ->and($user->passwordHasExpired())->toBeFalse()
        ->and($user->password_changed_at->isToday())->toBeTrue();
});

it('sends users without a forced or expired password away from the change screen', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ForcePasswordReset::class)->assertRedirect(route('dashboard'));
});

it('stamps password_changed_at on profile password changes', function () {
    $user = expiredPasswordUser();
    $this->actingAs($user);

    Livewire::test(ProfileIndex::class)
        ->set('current_password', 'Old!Passw0rd')
        ->set('new_password', 'New!Passw0rd')
        ->set('new_password_confirmation', 'New!Passw0rd')
        ->call('resetPassword')
        ->assertDispatched('success');

    expect($user->fresh()->passwordHasExpired())->toBeFalse();
});
