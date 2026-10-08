<?php

use App\Livewire\SettingsOauth;
use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\OauthSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    Cache::flush();
});

it('seeds only the OpenID Connect and Authentik login providers', function () {
    (new OauthSettingSeeder)->run();

    expect(OauthSetting::query()->orderBy('provider')->pluck('provider')->all())->toBe(['authentik', 'oidc']);
});

it('deletes the other providers and the identities linked through them', function () {
    $user = User::factory()->create();
    foreach (['azure', 'github', 'google', 'oidc', 'authentik'] as $provider) {
        OauthSetting::create(['provider' => $provider, 'client_id' => 'id', 'client_secret' => 'secret', 'enabled' => true]);
    }
    OauthIdentity::create(['user_id' => $user->id, 'provider' => 'github', 'issuer' => 'github', 'provider_user_id' => '1', 'email' => $user->email]);
    OauthIdentity::create(['user_id' => $user->id, 'provider' => 'oidc', 'issuer' => 'https://idp.example.com', 'provider_user_id' => 'sub', 'email' => $user->email]);

    (require base_path('database/migrations/2026_10_04_183033_remove_unsupported_oauth_providers.php'))->up();

    expect(OauthSetting::query()->orderBy('provider')->pluck('provider')->all())->toBe(['authentik', 'oidc'])
        ->and(OauthIdentity::query()->pluck('provider')->all())->toBe(['oidc']);
});

it('refuses sign-in through a removed provider even when its settings remain', function (string $provider) {
    OauthSetting::create(['provider' => $provider, 'client_id' => 'id', 'client_secret' => 'secret', 'tenant' => 'tenant', 'enabled' => true]);

    $this->get(route('auth.redirect', $provider))->assertForbidden();
    $this->get(route('auth.callback', $provider))->assertRedirect(route('login'));
    $this->assertGuest();
})->with(['azure', 'bitbucket', 'clerk', 'discord', 'github', 'gitlab', 'google', 'infomaniak', 'zitadel']);

it('shows only the supported providers on the login page and the settings page', function () {
    OauthSetting::create(['provider' => 'github', 'client_id' => 'id', 'client_secret' => 'secret', 'enabled' => true]);
    OauthSetting::create(['provider' => 'oidc', 'client_id' => 'id', 'client_secret' => 'secret', 'base_url' => 'https://idp.example.com', 'enabled' => true]);
    OauthSetting::create(['provider' => 'authentik']);
    User::factory()->create();

    $this->get('/login')->assertOk()->assertSee('/auth/oidc/redirect', false)->assertDontSee('/auth/github/redirect', false);

    $rootTeam = Team::factory()->create(['id' => 0]);
    $admin = User::factory()->create();
    $rootTeam->members()->attach($admin->id, ['role' => 'owner']);
    $this->actingAs($admin);
    session(['currentTeam' => $rootTeam]);

    expect(array_keys(Livewire::test(SettingsOauth::class)->get('oauth_settings_map')))->toEqualCanonicalizing(['oidc', 'authentik']);
});
