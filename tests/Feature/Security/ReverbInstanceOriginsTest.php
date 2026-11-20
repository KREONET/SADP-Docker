<?php

use App\Models\InstanceSettings;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Once::flush();
    config(['reverb.apps.apps.0.allowed_origins' => ['panel.example.com']]);
});

/**
 * @param  array<int, string>  $argv
 */
function configureReverbOriginsFor(array $argv): void
{
    $provider = new AppServiceProvider(app());
    (new ReflectionMethod($provider, 'configureReverbAllowedOrigins'))->invoke($provider, $argv);
}

it('adds the instance domain from the settings when reverb starts', function () {
    InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://coolify.example.org']);

    configureReverbOriginsFor(['artisan', 'reverb:start', '--host=0.0.0.0']);

    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['panel.example.com', 'coolify.example.org']);
});

it('leaves the origins alone for other commands', function () {
    InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://coolify.example.org']);

    configureReverbOriginsFor(['artisan', 'queue:work']);

    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['panel.example.com']);
});

it('keeps allowing every origin when no origin restriction is configured', function () {
    config(['reverb.apps.apps.0.allowed_origins' => ['*']]);
    InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://coolify.example.org']);

    configureReverbOriginsFor(['artisan', 'reverb:start']);

    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['*']);
});

it('keeps the configured origins when the settings cannot be read', function () {
    Schema::drop('instance_settings');

    configureReverbOriginsFor(['artisan', 'reverb:start']);

    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['panel.example.com']);
});
