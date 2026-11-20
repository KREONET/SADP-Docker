<?php

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0, 'is_sso_only_login_enabled' => true, 'is_registration_enabled' => true]);
    OauthSetting::create([
        'provider' => 'oidc',
        'client_id' => 'client',
        'client_secret' => 'secret',
        'base_url' => 'https://sso.example.com',
        'enabled' => true,
    ]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['email' => 'dev@example.com', 'password' => Hash::make('password')]);
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
});

test('password login is rejected for regular users when sso only is active', function () {
    $this->post('/login', ['email' => 'dev@example.com', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('the root user keeps password login as a break glass account', function () {
    $root = User::find(0) ?? User::factory()->create(['id' => 0, 'email' => 'root@example.com', 'password' => Hash::make('password')]);

    expect(InstanceSettings::get()->isPasswordLoginAllowedFor($root))->toBeTrue()
        ->and(InstanceSettings::get()->isPasswordLoginAllowedFor($this->user))->toBeFalse();
});

test('password registration is disabled while sso only is active', function () {
    expect(InstanceSettings::get()->isPasswordRegistrationAllowed())->toBeFalse();
});

test('sso only is inactive when no provider is enabled', function () {
    OauthSetting::query()->update(['enabled' => false]);

    expect(InstanceSettings::get()->isSsoOnlyLoginActive())->toBeFalse();

    $this->post('/login', ['email' => 'dev@example.com', 'password' => 'password']);
    $this->assertAuthenticatedAs($this->user);
});

test('the login page shows the sso button and hides the password form behind a disclosure', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('/auth/oidc/redirect', false)
        ->assertSee('Administrator password login');
});
