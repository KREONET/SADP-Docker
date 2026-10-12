<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0, 'brand_name' => 'KREONET iApp']);
    $this->team = Team::factory()->create(['show_boarding' => false]);
    $this->user = User::factory()->create(['name' => 'Brand User']);
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
});

test('authenticator apps show the registered brand name instead of APP_NAME', function () {
    config(['app.name' => 'Coolify']);
    $this->user->forceFill(['two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP')])->save();

    $url = $this->user->fresh()->twoFactorQrCodeUrl();

    expect($url)->toStartWith('otpauth://totp/'.rawurlencode('KREONET iApp').':')
        ->and($url)->toContain('issuer='.rawurlencode('KREONET iApp'))
        ->and($url)->not->toContain('Coolify');
});

test('the documentation menu explains that the Coolify docs open instead', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('The KREONET iApp documentation is not finished yet, so the Coolify documentation opens instead.', false)
        ->assertSee('Open Coolify Docs');
});
