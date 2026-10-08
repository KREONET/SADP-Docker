<?php

use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChanged;
use App\Events\ProxyStatusChangedUI;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Once;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Event::fake([ProxyStatusChanged::class, ProxyStatusChangedUI::class]);
    config([
        'app.maintenance.store' => 'array',
        'constants.ssh.mux_enabled' => false,
    ]);

    $this->commands = [];
    Process::fake(function ($process) {
        $this->commands[] = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return Process::result(output: '');
    });

    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

function hardeningServer(object $test, string $proxyType, string $user = 'root', ?int $id = null): Server
{
    $attributes = [
        'team_id' => $test->team->id,
        'user' => $user,
        'private_key_id' => $test->privateKey->id,
        'proxy' => ['type' => $proxyType, 'status' => 'exited'],
    ];
    if ($id !== null) {
        $attributes['id'] = $id;
    }
    $server = Server::factory()->create($attributes);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);

    return $server->refresh();
}

/**
 * Decodes the content written by `echo '<base64>' | base64 -d | tee <file>` for the given file name.
 */
function writtenProxyFile(array $commands, string $fileName): ?string
{
    $pattern = '/echo \'([A-Za-z0-9+\/=]+)\' \| base64 -d \| (?:sudo )?tee \S*\/'.preg_quote($fileName, '/').' /';
    foreach (array_reverse($commands) as $command) {
        if (preg_match($pattern, $command, $matches)) {
            return base64_decode($matches[1]);
        }
    }

    return null;
}

/**
 * @return array<int, string>
 */
function routerMiddlewares(array $labels, string $router): array
{
    $label = collect($labels)->first(fn (string $label) => str_starts_with($label, "traefik.http.routers.{$router}.middlewares="));

    return $label === null ? [] : explode(',', str($label)->after('.middlewares=')->toString());
}

describe('Traefik default TLS options', function () {
    it('limits TLS to 1.2+, ECDHE AES-GCM suites and NIST curves', function () {
        $options = Yaml::parse(traefikTlsOptionsConfiguration())['tls']['options']['default'];

        expect($options)->toBe([
            'minVersion' => 'VersionTLS12',
            'cipherSuites' => [
                'TLS_ECDHE_ECDSA_WITH_AES_256_GCM_SHA384',
                'TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384',
                'TLS_ECDHE_ECDSA_WITH_AES_128_GCM_SHA256',
                'TLS_ECDHE_RSA_WITH_AES_128_GCM_SHA256',
            ],
            'curvePreferences' => ['CurveP521', 'CurveP384', 'CurveP256'],
        ]);
    });

    it('writes the TLS options file whenever the Traefik proxy configuration is saved', function () {
        $server = hardeningServer($this, ProxyTypes::TRAEFIK->value);

        SaveProxyConfiguration::run($server, "services:\n  traefik:\n    image: traefik:v3.7\n");

        expect(writtenProxyFile($this->commands, 'coolify-tls.yaml'))->toBe(traefikTlsOptionsConfiguration())
            ->and(implode("\n", $this->commands))->toContain('tee /data/coolify/proxy/dynamic/coolify-tls.yaml > /dev/null');
    });

    it('writes the TLS options file through sudo for a non-root SSH user', function () {
        $server = hardeningServer($this, ProxyTypes::TRAEFIK->value, 'cooluser');

        $lines = parseCommandsByLineForSudo(collect(traefikTlsOptionsCommands($server)), $server);

        expect($lines[0])->toStartWith('sudo mkdir -p /data/coolify/proxy/dynamic')
            ->and($lines[1])->toEndWith('| sudo tee /data/coolify/proxy/dynamic/coolify-tls.yaml > /dev/null');
    });

    it('does not write Traefik TLS options for a Caddy proxy', function () {
        $server = hardeningServer($this, ProxyTypes::CADDY->value);

        SaveProxyConfiguration::run($server, "services:\n  caddy:\n    image: lucaslorentz/caddy-docker-proxy:2.13-alpine\n");

        expect(traefikTlsOptionsCommands($server))->toBe([])
            ->and(implode("\n", $this->commands))->not->toContain('coolify-tls.yaml');
    });
});

describe('Traefik proxy compose', function () {
    it('does not publish the Traefik API port outside development', function () {
        $server = hardeningServer($this, ProxyTypes::TRAEFIK->value);

        $traefik = Yaml::parse(generateDefaultProxyConfiguration($server))['services']['traefik'];

        expect($traefik['ports'])->toBe(['80:80', '443:443'])
            ->and($traefik['command'])->toContain('--api.insecure=false');
    });

    it('publishes the Traefik API port in development only', function () {
        config(['app.env' => 'local']);
        $server = hardeningServer($this, ProxyTypes::TRAEFIK->value);

        $traefik = Yaml::parse(generateDefaultProxyConfiguration($server))['services']['traefik'];

        expect($traefik['ports'])->toContain('8080:8080')
            ->and($traefik['command'])->toContain('--api.insecure=true');
    });
});

describe('Traefik HSTS labels', function () {
    it('adds the HSTS middleware to HTTPS routers only', function (bool $forceHttps) {
        $labels = fqdnLabelsForTraefik(
            uuid: 'appuuid',
            domains: collect(['https://app.example.com']),
            is_force_https_enabled: $forceHttps,
            onlyPort: 3000,
        )->values()->all();

        expect($labels)
            ->toContain('traefik.http.middlewares.coolify-hsts.headers.stsSeconds=31536000')
            ->toContain('traefik.http.middlewares.coolify-hsts.headers.stsIncludeSubdomains=true')
            ->toContain('traefik.http.middlewares.coolify-hsts.headers.stsPreload=false')
            ->and(routerMiddlewares($labels, 'https-0-appuuid'))->toBe(['coolify-hsts', 'gzip'])
            ->and(routerMiddlewares($labels, 'http-0-appuuid'))->not->toContain('coolify-hsts');
    })->with([
        'force HTTPS on' => [true],
        'force HTTPS off' => [false],
    ]);

    it('does not define HSTS for HTTP-only domains', function () {
        $labels = fqdnLabelsForTraefik(
            uuid: 'appuuid',
            domains: collect(['http://app.example.com']),
            onlyPort: 3000,
        )->values()->all();

        expect(implode("\n", $labels))->not->toContain('coolify-hsts');
    });

    it('defines the HSTS middleware once for several HTTPS domains', function () {
        $labels = fqdnLabelsForTraefik(
            uuid: 'appuuid',
            domains: collect(['https://a.example.com', 'https://b.example.com']),
            onlyPort: 3000,
        )->values()->all();

        expect(collect($labels)->filter(fn (string $label) => str_contains($label, 'coolify-hsts.headers.stsSeconds'))->count())->toBe(1)
            ->and(routerMiddlewares($labels, 'https-1-appuuid'))->toContain('coolify-hsts');
    });

    it('keeps exposure restrictions first in the HTTPS middleware chain', function () {
        $labels = fqdnLabelsForTraefik(
            uuid: 'appuuid',
            domains: collect(['https://app.example.com/api']),
            onlyPort: 3000,
            access: ['mode' => 'internal', 'allowed_ranges' => ['10.0.0.0/8']],
        )->values()->all();

        expect(routerMiddlewares($labels, 'https-0-appuuid'))
            ->toBe(['internal-only-appuuid', 'coolify-hsts', 'https-0-appuuid-stripprefix', 'gzip']);
    });

    it('does not duplicate the HSTS middleware taken from service labels', function () {
        $labels = fqdnLabelsForTraefik(
            uuid: 'appuuid',
            domains: collect(['https://app.example.com']),
            onlyPort: 3000,
            serviceLabels: collect(['traefik.http.middlewares.coolify-hsts.headers.stsSeconds=31536000']),
        )->values()->all();

        expect(routerMiddlewares($labels, 'https-0-appuuid'))->toBe(['coolify-hsts', 'gzip']);
    });

    it('reads the HSTS max-age from the security configuration', function () {
        config(['security.hsts.max_age_seconds' => 63072000, 'security.hsts.include_subdomains' => false]);

        expect(traefikHstsMiddlewareLabels())->toContain('traefik.http.middlewares.coolify-hsts.headers.stsSeconds=63072000')
            ->toContain('traefik.http.middlewares.coolify-hsts.headers.stsIncludeSubdomains=false')
            ->and(proxyHstsHeaderValue())->toBe('max-age=63072000');
    });
});

describe('Caddy HTTPS hardening labels', function () {
    it('adds HSTS and the TLS policy to HTTPS-only sites', function () {
        $labels = fqdnLabelsForCaddy(
            network: 'coolify',
            uuid: 'appuuid',
            domains: collect(['https://app.example.com']),
            is_force_https_enabled: true,
            onlyPort: 3000,
        )->values()->all();

        expect($labels)
            ->toContain('caddy_0=https://app.example.com')
            ->toContain('caddy_0.header_1=Strict-Transport-Security "max-age=31536000; includeSubDomains"')
            ->toContain('caddy_0.tls.protocols=tls1.2 tls1.3')
            ->toContain('caddy_0.tls.ciphers=TLS_ECDHE_ECDSA_WITH_AES_256_GCM_SHA384 TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384 TLS_ECDHE_ECDSA_WITH_AES_128_GCM_SHA256 TLS_ECDHE_RSA_WITH_AES_128_GCM_SHA256')
            ->toContain('caddy_0.tls.curves=secp521r1 secp384r1 secp256r1')
            ->toContain('caddy_0.header=-Server');
    });

    it('sends HSTS on HTTPS only and skips the TLS policy when the site also serves HTTP', function () {
        $labels = fqdnLabelsForCaddy(
            network: 'coolify',
            uuid: 'appuuid',
            domains: collect(['https://app.example.com']),
            is_force_https_enabled: false,
            onlyPort: 3000,
        )->values()->all();

        expect($labels)
            ->toContain('caddy_0=http://app.example.com, https://app.example.com')
            ->toContain('caddy_0.@coolify_https.protocol=https')
            ->toContain('caddy_0.header_1=@coolify_https Strict-Transport-Security "max-age=31536000; includeSubDomains"')
            ->and(implode("\n", $labels))->not->toContain('caddy_0.tls');
    });

    it('adds no HSTS or TLS labels to HTTP sites', function () {
        $labels = fqdnLabelsForCaddy(
            network: 'coolify',
            uuid: 'appuuid',
            domains: collect(['http://app.example.com']),
            onlyPort: 3000,
        )->values()->all();

        expect(implode("\n", $labels))->not->toContain('Strict-Transport-Security')
            ->not->toContain('.tls.');
    });
});

describe('dashboard proxy configuration', function () {
    beforeEach(function () {
        Team::find(0) ?? Team::factory()->create(['id' => 0]);
    });

    it('adds HSTS to every HTTPS router of the Traefik dashboard configuration', function () {
        InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://dashboard.example.com']);
        Once::flush();
        $server = hardeningServer($this, ProxyTypes::TRAEFIK->value, id: 0);

        $server->setupDynamicProxyConfiguration();

        $config = Yaml::parse(writtenProxyFile($this->commands, 'coolify.yaml'));
        expect($config['http']['middlewares']['coolify-hsts']['headers'])->toBe([
            'stsSeconds' => 31536000,
            'stsIncludeSubdomains' => true,
            'stsPreload' => false,
        ]);
        foreach (['coolify-https', 'coolify-reverb-wss', 'coolify-reverb-api-https', 'coolify-terminal-wss'] as $router) {
            expect($config['http']['routers'][$router]['middlewares'])->toContain('coolify-hsts');
        }
        foreach (['coolify-http', 'coolify-reverb-ws', 'coolify-reverb-api', 'coolify-terminal-ws'] as $router) {
            expect($config['http']['routers'][$router]['middlewares'] ?? [])->not->toContain('coolify-hsts');
        }
    });

    it('does not add HSTS to an HTTP-only Traefik dashboard', function () {
        InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'http://dashboard.example.com']);
        Once::flush();
        $server = hardeningServer($this, ProxyTypes::TRAEFIK->value, id: 0);

        $server->setupDynamicProxyConfiguration();

        expect(writtenProxyFile($this->commands, 'coolify.yaml'))->not->toContain('coolify-hsts');
    });

    it('adds HSTS and the TLS policy to an HTTPS-only Caddy dashboard', function () {
        InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://dashboard.example.com']);
        Once::flush();
        $server = hardeningServer($this, ProxyTypes::CADDY->value, id: 0);

        $server->setupDynamicProxyConfiguration();

        expect(writtenProxyFile($this->commands, 'coolify.caddy'))
            ->toContain("https://dashboard.example.com {\n    encode zstd gzip\n    header Strict-Transport-Security \"max-age=31536000; includeSubDomains\"\n    tls {\n        protocols tls1.2 tls1.3\n")
            ->toContain('        curves secp521r1 secp384r1 secp256r1');
    });

    it('limits Caddy dashboard HSTS to HTTPS requests when HTTP is also served', function () {
        $server = new Server;

        expect($server->dashboardCaddyHttpsHardening('http://dashboard.example.com, https://dashboard.example.com', 'https'))
            ->toBe("    @coolify_https protocol https\n    header @coolify_https Strict-Transport-Security \"max-age=31536000; includeSubDomains\"\n")
            ->and($server->dashboardCaddyHttpsHardening('http://dashboard.example.com', 'http'))->toBe('');
    });
});
