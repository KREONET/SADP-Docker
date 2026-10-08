<?php

use App\Livewire\Settings\Advanced;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('instance admin can change registration after confirming the password', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    $settings = InstanceSettings::forceCreate([
        'id' => 0,
        'is_registration_enabled' => false,
    ]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    $component = Livewire::test(Advanced::class)
        ->assertSet('is_registration_enabled', false)
        ->assertSee('Registration')
        ->assertDontSee('Only administrators can create accounts.')
        ->set('is_registration_enabled', true)
        ->call('instantSave');

    // Registration is password protected, so the listbox no longer saves it instantly.
    expect((bool) $settings->fresh()->is_registration_enabled)->toBeFalse();

    $component->call('submit')
        ->assertDispatched('confirm-security-settings');
    expect((bool) $settings->fresh()->is_registration_enabled)->toBeFalse();

    $component->call('submit', 'password')
        ->assertDispatched('success');
    expect((bool) $settings->fresh()->is_registration_enabled)->toBeTrue();
});

test('the two-step confirmation bypass is no longer offered', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    $settings = InstanceSettings::forceCreate([
        'id' => 0,
        'is_registration_enabled' => true,
        'disable_two_step_confirmation' => false,
    ]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Advanced::class)
        ->assertDontSee('Destructive action confirmation')
        ->assertDontSee('Skip two-step confirmation')
        ->call('instantSave')
        ->assertDispatched('success');

    expect((bool) $settings->fresh()->disable_two_step_confirmation)->toBeFalse();
});

test('instance admin can configure the image CDN URL at runtime', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    $settings = InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Advanced::class)
        ->assertSee('Image CDN URL')
        ->set('image_cdn_url', 'https://images.example.com/media/')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($settings->fresh()->image_cdn_url)->toBe('https://images.example.com/media');
});

test('open API allowlist warning is hidden when API access is disabled', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    InstanceSettings::forceCreate([
        'id' => 0,
        'is_api_enabled' => false,
        'allowed_ips' => null,
    ]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Advanced::class)
        ->assertSet('is_api_enabled', false)
        ->assertDontSee('API access is open to every source');
});

test('open API allowlist warning is shown when API access is enabled without allowlist', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    InstanceSettings::forceCreate([
        'id' => 0,
        'is_api_enabled' => true,
        'allowed_ips' => null,
    ]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Advanced::class)
        ->assertSet('is_api_enabled', true)
        ->assertSee('API access is open to every source');
});
