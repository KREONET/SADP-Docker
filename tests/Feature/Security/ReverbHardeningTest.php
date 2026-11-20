<?php

use App\Support\ReverbSettings;

/**
 * Loads a config file with temporary environment values.
 *
 * @param  array<string, string|null>  $environment
 */
function loadConfigWithEnvironment(string $file, array $environment): array
{
    $previous = [];

    foreach ($environment as $key => $value) {
        $previous[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
        } else {
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
        }
    }

    try {
        return require config_path($file);
    } finally {
        foreach ($previous as $key => [$server, $env]) {
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
        }
    }
}

it('keeps the development secret only in development environments', function (string $environment) {
    expect(ReverbSettings::secret(null, $environment, 'base64:key'))->toBe('coolify');
})->with(['local', 'development', 'testing']);

it('never falls back to the public coolify secret in production', function () {
    $derived = ReverbSettings::secret(null, 'production', 'base64:instance-key');

    expect($derived)->not->toBe('coolify')
        ->toBe(hash_hmac('sha256', 'coolify-reverb-app-secret', 'base64:instance-key'))
        ->and(ReverbSettings::secret('', 'production', 'base64:instance-key'))->toBe($derived)
        ->and(ReverbSettings::secret(null, 'production', 'base64:other-key'))->not->toBe($derived)
        ->and(ReverbSettings::secret(null, null, null))->toMatch('/^[0-9a-f]{64}$/')
        ->and(ReverbSettings::secret('configured-secret', 'production', 'base64:instance-key'))->toBe('configured-secret');
});

it('uses the same secret for the Reverb server and the broadcaster', function () {
    $environment = ['APP_ENV' => 'production', 'APP_KEY' => 'base64:instance-key', 'PUSHER_APP_SECRET' => null];

    $reverb = loadConfigWithEnvironment('reverb.php', $environment);
    $broadcasting = loadConfigWithEnvironment('broadcasting.php', $environment);

    $secret = $reverb['apps']['apps'][0]['secret'];

    expect($secret)->not->toBe('coolify')
        ->and($broadcasting['connections']['reverb']['secret'])->toBe($secret)
        ->and($broadcasting['connections']['pusher']['secret'])->toBe($secret);
});

it('derives allowed origins from APP_URL and lets REVERB_ALLOWED_ORIGINS override them', function () {
    $production = loadConfigWithEnvironment('reverb.php', [
        'APP_ENV' => 'production',
        'APP_URL' => 'https://coolify.example.com:8443/',
        'REVERB_ALLOWED_ORIGINS' => null,
    ]);
    $overridden = loadConfigWithEnvironment('reverb.php', [
        'APP_ENV' => 'production',
        'APP_URL' => 'https://coolify.example.com',
        'REVERB_ALLOWED_ORIGINS' => 'https://panel.example.org, *.example.net ,203.0.113.10:8000',
    ]);

    expect($production['apps']['apps'][0]['allowed_origins'])->toBe(['coolify.example.com'])
        ->and($overridden['apps']['apps'][0]['allowed_origins'])->toBe(['panel.example.org', '*.example.net', '203.0.113.10']);
});

it('keeps every origin allowed when no panel address is configured or in development', function () {
    expect(ReverbSettings::allowedOrigins(null, null, 'production'))->toBe(['*'])
        ->and(ReverbSettings::allowedOrigins('', '', 'production'))->toBe(['*'])
        ->and(ReverbSettings::allowedOrigins(null, 'http://localhost:8000', 'local'))->toBe(['*'])
        ->and(ReverbSettings::allowedOrigins('*', 'https://coolify.example.com', 'production'))->toBe(['*']);
});

it('adds instance domains to restricted origins only', function () {
    expect(ReverbSettings::withInstanceUrls(['coolify.example.com'], ['https://panel.example.org', null]))
        ->toBe(['coolify.example.com', 'panel.example.org'])
        ->and(ReverbSettings::withInstanceUrls(['*'], ['https://panel.example.org']))->toBe(['*']);
});

it('parses origins into host names that Reverb can match', function () {
    expect(ReverbSettings::parseOrigins('HTTPS://A.Example.com/path, b.example.com:6001, http://[2001:db8::1]:8000, [::1], javascript:alert(1), bad host, '))
        ->toBe(['a.example.com', 'b.example.com', '[2001:db8::1]', '[::1]']);
});
