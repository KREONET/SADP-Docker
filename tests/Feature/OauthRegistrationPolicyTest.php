<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate([
        'id' => 0,
        'is_registration_enabled' => true,
        'disable_registration_when_oauth_enabled' => true,
    ]);
    Once::flush();
});

it('blocks password registration when oauth registration policy disables it', function () {
    OauthSetting::create([
        'provider' => 'oidc',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'secret',
        'base_url' => 'https://idp.example.com',
    ]);

    app(CreateNewUser::class)->create([
        'name' => 'Password User',
        'email' => 'password@example.com',
        'password' => 'Str0ng!Pass',
        'password_confirmation' => 'Str0ng!Pass',
    ]);
})->throws(HttpException::class);

it('allows password registration when no oauth provider is enabled', function () {
    OauthSetting::create([
        'provider' => 'oidc',
        'enabled' => false,
    ]);

    $user = app(CreateNewUser::class)->create([
        'name' => 'Password User',
        'email' => 'password@example.com',
        'password' => 'Str0ng!Pass',
        'password_confirmation' => 'Str0ng!Pass',
    ]);

    expect($user->email)->toBe('password@example.com')
        ->and($user->email_verified_at)->toBeNull();
});
