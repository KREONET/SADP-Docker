<?php

use App\Auth\Oidc\OidcUser;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OauthLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('security.lockout.max_attempts', 3);
    config()->set('security.lockout.minutes', 30);
    InstanceSettings::forceCreate(['id' => 0, 'is_registration_enabled' => false]);
    Once::flush();
    Cache::flush();
    Team::factory()->create(['id' => 0, 'name' => 'Root Team']);

    $this->user = User::factory()->create([
        'email' => 'locked@example.com',
        'password' => Hash::make('Corr3ct!Pass'),
    ]);
});

function failLogins(int $times, string $email = 'locked@example.com'): void
{
    for ($i = 0; $i < $times; $i++) {
        test()->post('/login', ['email' => $email, 'password' => 'Wrong!Pass1']);
    }
}

it('counts failed password logins without locking below the threshold', function () {
    failLogins(2);

    $this->user->refresh();
    expect($this->user->failed_login_attempts)->toBe(2)
        ->and($this->user->isLockedOut())->toBeFalse();

    $this->post('/login', ['email' => 'locked@example.com', 'password' => 'Corr3ct!Pass']);
    $this->assertAuthenticatedAs($this->user->fresh());
});

it('locks the account after the configured number of failures and audits it', function () {
    failLogins(3);

    $this->user->refresh();
    expect($this->user->isLockedOut())->toBeTrue()
        ->and($this->user->locked_until->between(now()->addMinutes(29), now()->addMinutes(31)))->toBeTrue();

    $event = AuditEvent::query()->where('event', 'auth.login.locked')->sole();
    expect($event->level)->toBe('warning')
        ->and($event->metadata['result'])->toBe('denied')
        ->and($event->metadata['reason'])->toBe('max_attempts_reached')
        ->and((int) $event->actor_id)->toBe($this->user->id);
});

it('refuses a locked account even with the correct password', function () {
    failLogins(3);

    $this->post('/login', ['email' => 'locked@example.com', 'password' => 'Corr3ct!Pass'])
        ->assertSessionHasErrors(['email' => __('This account is temporarily locked after too many failed sign-in attempts. Try again in :minutes minutes or ask an administrator to unlock it.', ['minutes' => 30])]);

    $this->assertGuest();
    expect(AuditEvent::query()->where('event', 'auth.login.locked')->where('metadata->reason', 'attempt_while_locked')->exists())->toBeTrue();
});

it('answers a wrong password on a locked account like an unknown email', function () {
    failLogins(3);

    $locked = $this->post('/login', ['email' => 'locked@example.com', 'password' => 'Wrong!Pass1']);
    $unknown = $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'Wrong!Pass1']);

    $locked->assertSessionHasErrors(['email' => __('auth.failed')]);
    $unknown->assertSessionHasErrors(['email' => __('auth.failed')]);
    $this->assertGuest();
    // A wrong password while locked does not extend the lock or the counter.
    expect($this->user->fresh()->failed_login_attempts)->toBe(3);
});

it('does not create anything for failed logins with an unknown email', function () {
    $users = User::query()->count();

    failLogins(3, 'ghost@example.com');

    expect(User::query()->count())->toBe($users)
        ->and(User::query()->where('email', 'ghost@example.com')->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('event', 'auth.login.locked')->exists())->toBeFalse();
});

it('allows sign-in again after the lock expires and starts counting from zero', function () {
    failLogins(3);
    $this->travel(31)->minutes();

    $this->post('/login', ['email' => 'locked@example.com', 'password' => 'Wrong!Pass1']);
    expect($this->user->fresh()->failed_login_attempts)->toBe(1)
        ->and($this->user->fresh()->isLockedOut())->toBeFalse();

    $this->post('/login', ['email' => 'locked@example.com', 'password' => 'Corr3ct!Pass']);
    $this->assertAuthenticatedAs($this->user->fresh());
});

it('records the successful password login and resets the counter', function () {
    failLogins(2);

    $this->post('/login', ['email' => 'locked@example.com', 'password' => 'Corr3ct!Pass']);

    $this->user->refresh();
    expect($this->user->failed_login_attempts)->toBe(0)
        ->and($this->user->last_login_at)->not->toBeNull();
});

it('records the successful oidc login and marks the session as sso', function () {
    $setting = OauthSetting::create([
        'provider' => 'oidc',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'base_url' => 'https://idp.example.com',
        'allow_registration' => false,
    ]);
    OauthIdentity::create([
        'user_id' => $this->user->id,
        'provider' => 'oidc',
        'issuer' => 'https://idp.example.com',
        'provider_user_id' => 'lockout-subject',
        'email' => $this->user->email,
    ]);
    $oidcUser = (new OidcUser)->setRaw([
        'iss' => 'https://idp.example.com',
        'sub' => 'lockout-subject',
        'email' => $this->user->email,
        'email_verified' => true,
    ])->map(['id' => 'lockout-subject', 'name' => $this->user->name, 'email' => $this->user->email]);

    app(OauthLoginService::class)->login('oidc', $oidcUser, $setting);

    expect($this->user->fresh()->last_login_at)->not->toBeNull()
        ->and(session(OauthLoginService::SSO_SESSION_KEY))->toBe('oidc');
});

it('clears a stale sso marker on a password login', function () {
    $this->withSession([OauthLoginService::SSO_SESSION_KEY => 'oidc'])
        ->post('/login', ['email' => 'locked@example.com', 'password' => 'Corr3ct!Pass']);

    $this->assertAuthenticated();
    expect(session()->has(OauthLoginService::SSO_SESSION_KEY))->toBeFalse();
});
