<?php

use App\Livewire\OpenSourceLicenses;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Services\OpenSourceLicenses as OpenSourceLicenseInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->team = Team::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member->id, ['role' => 'member']);
});

function actAsLicenseReader(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
    Cache::flush();
}

test('guests cannot see the installed package versions', function () {
    $this->get('/open-source-licenses')->assertRedirect('/login');
});

test('any signed-in member can open the open source notices', function () {
    actAsLicenseReader($this->member, $this->team);

    $this->get('/open-source-licenses')
        ->assertOk()
        ->assertSee('Open Source Licenses')
        ->assertSee('Apache License 2.0')
        ->assertSee('laravel/framework');
});

test('the user menu links to the open source notices', function () {
    actAsLicenseReader($this->member, $this->team);

    $this->get('/open-source-licenses')->assertSee(route('open-source-licenses'), false)->assertSee('Open source licenses');
});

test('packages can be searched and filtered by component and license', function () {
    actAsLicenseReader($this->member, $this->team);

    Livewire::test(OpenSourceLicenses::class)
        ->set('search', 'livewire/livewire')
        ->assertSee('livewire/livewire')
        ->assertDontSee('guzzlehttp/guzzle')
        ->set('search', '')
        ->set('component', OpenSourceLicenseInventory::COMPONENT_TERMINAL)
        ->assertSee('node-pty')
        ->assertDontSee('laravel/framework')
        ->set('component', 'all')
        ->set('license', 'GPL-3.0-only')
        ->assertSee('purplepixie/phpdns')
        ->assertDontSee('laravel/framework');
});

test('the license text of a package is shown on request', function () {
    actAsLicenseReader($this->member, $this->team);
    $package = collect(OpenSourceLicenseInventory::packages())->firstWhere('name', 'laravel/framework');

    Livewire::test(OpenSourceLicenses::class)
        ->set('search', 'laravel/framework')
        ->assertDontSee('Permission is hereby granted')
        ->call('toggle', $package['key'])
        ->assertSee('Permission is hereby granted')
        ->call('toggle', $package['key'])
        ->assertDontSee('Permission is hereby granted');
});

test('the coolify apache license can be shown', function () {
    actAsLicenseReader($this->member, $this->team);

    Livewire::test(OpenSourceLicenses::class)
        ->assertDontSee('TERMS AND CONDITIONS FOR USE')
        ->set('showCoolifyLicense', true)
        ->assertSee('TERMS AND CONDITIONS FOR USE');
});

test('the committed notices match the lock files', function () {
    $listed = collect(OpenSourceLicenseInventory::packages())
        ->mapWithKeys(fn (array $package): array => [$package['key'] => $package['version']])
        ->sortKeys()
        ->all();

    expect($listed)->toBe(OpenSourceLicenseInventory::expectedVersions(base_path()),
        'Dependencies changed. Run php artisan generate:open-source-licenses with vendor/ and node_modules/ installed.');
});

test('every listed package has a license or a license text', function () {
    $unlicensed = collect(OpenSourceLicenseInventory::packages())
        ->filter(fn (array $package): bool => $package['licenses'] === [] && $package['license_text'] === null)
        ->pluck('key');

    expect($unlicensed->all())->toBe([]);
});
