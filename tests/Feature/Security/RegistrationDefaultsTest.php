<?php

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    Once::flush();
});

test('new installations do not allow open registration by default', function () {
    InstanceSettings::forceCreate(['id' => 0]);

    expect(InstanceSettings::query()->find(0)->is_registration_enabled)->toBeFalse();
});

test('existing instance settings keep an explicit registration choice', function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_registration_enabled' => true]);

    expect(InstanceSettings::query()->find(0)->is_registration_enabled)->toBeTrue();
});

test('new oauth providers do not create accounts by default', function () {
    $model = OauthSetting::create(['provider' => 'oidc']);
    DB::table('oauth_settings')->insert(['provider' => 'authentik', 'created_at' => now(), 'updated_at' => now()]);

    expect($model->allow_registration)->toBeFalse()
        ->and($model->fresh()->allow_registration)->toBeFalse()
        ->and(OauthSetting::query()->where('provider', 'authentik')->sole()->allow_registration)->toBeFalse();
});

test('the first user can still register on a fresh installation', function () {
    InstanceSettings::forceCreate(['id' => 0]);
    expect(User::query()->count())->toBe(0);

    $this->get('/register')->assertOk();
});

test('registration closes once the first user exists', function () {
    InstanceSettings::forceCreate(['id' => 0]);
    User::factory()->create(['id' => 0]);

    $this->get('/register')->assertRedirect(route('login'));
});
