<?php

use App\Livewire\Platform\Security;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use App\Support\InstanceSettingsAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0]);
    Cache::flush();

    $rootTeam = Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->admin = User::factory()->create();
    $rootTeam->members()->attach($this->admin->id, ['role' => 'admin']);
    $this->actingAs($this->admin);
    session(['currentTeam' => $rootTeam]);

    OauthSetting::create([
        'provider' => 'oidc',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'base_url' => 'https://idp.example.com',
    ]);
});

test('enabling sso only login waits for the password confirmation', function () {
    Livewire::test(Security::class)
        ->set('isSsoOnlyLoginEnabled', true)
        ->call('saveLogin')
        ->assertDispatched('confirm-login-policy');

    expect(InstanceSettings::query()->find(0)->is_sso_only_login_enabled)->toBeFalse();
});

test('a wrong password does not change the login policy', function () {
    Livewire::test(Security::class)
        ->set('isSsoOnlyLoginEnabled', true)
        ->call('saveLogin', 'wrong-password')
        ->assertReturned('The provided password is incorrect.');

    expect(InstanceSettings::query()->find(0)->is_sso_only_login_enabled)->toBeFalse()
        ->and(AuditEvent::query()->where('event', InstanceSettingsAudit::EVENT)->where('result', 'denied')->exists())->toBeTrue();
});

test('the confirmed change is saved, audited with old and new values, and alerted', function () {
    Log::spy();

    Livewire::test(Security::class)
        ->set('isSsoOnlyLoginEnabled', true)
        ->call('saveLogin', 'password')
        ->assertReturned(true);

    expect(InstanceSettings::query()->find(0)->is_sso_only_login_enabled)->toBeTrue();

    $event = AuditEvent::query()->where('event', InstanceSettingsAudit::EVENT)->where('result', 'success')->sole();
    expect($event->metadata['changes']['is_sso_only_login_enabled'])->toBe(['old' => false, 'new' => true]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'Security alert:')
            && ($context['type'] ?? null) === 'instance_security_setting_changed')
        ->once();
});

test('saving an unchanged login policy needs no confirmation', function () {
    Livewire::test(Security::class)
        ->call('saveLogin')
        ->assertNotDispatched('confirm-login-policy')
        ->assertReturned(true);
});
