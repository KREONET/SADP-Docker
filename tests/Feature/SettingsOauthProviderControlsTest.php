<?php

use App\Models\OauthSetting;

it('requires the provider-specific fields used by oauth enablement', function (string $provider, array $requiredFields) {
    $complete = [
        'provider' => $provider,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'tenant' => 'tenant-id',
        'base_url' => 'https://auth.example.com',
    ];

    expect((new OauthSetting($complete))->couldBeEnabled())->toBeTrue();

    foreach ($requiredFields as $field) {
        expect((new OauthSetting([...$complete, $field => null]))->couldBeEnabled())
            ->toBeFalse("{$provider} must require {$field}");
    }
})->with([
    'oidc' => ['oidc', ['client_id', 'client_secret', 'base_url']],
    'authentik' => ['authentik', ['client_id', 'client_secret', 'base_url']],
]);

it('never enables a removed login provider', function (string $provider) {
    $setting = new OauthSetting([
        'provider' => $provider,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'tenant' => 'tenant-id',
        'base_url' => 'https://auth.example.com',
    ]);

    expect($setting->couldBeEnabled())->toBeFalse();
})->with(['azure', 'bitbucket', 'clerk', 'discord', 'github', 'gitlab', 'google', 'infomaniak', 'zitadel']);
