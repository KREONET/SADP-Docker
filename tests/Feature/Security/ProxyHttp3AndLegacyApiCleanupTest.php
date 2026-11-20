<?php

use App\Actions\Proxy\GetProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0]);
    Process::fake();
    config([
        'app.env' => 'production',
        'app.maintenance.store' => 'array',
        'constants.ssh.mux_enabled' => false,
        'security.proxy.http3' => false,
    ]);
});

function http3CleanupServer(string $proxyType, ?string $configuration = null, bool $applied = true): Server
{
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->proxy->type = $proxyType;
    $server->proxy->status = 'running';
    if ($configuration !== null) {
        $server->proxy->last_saved_proxy_configuration = $configuration;
        $server->proxy->last_saved_settings = md5(base64_encode($configuration));
        $server->proxy->last_applied_settings = $applied ? $server->proxy->last_saved_settings : null;
    }
    $server->save();

    return $server->fresh();
}

/**
 * A Traefik configuration as older Coolify versions saved it, with a comment the user added.
 */
function legacyHttp3TraefikConfiguration(string $apiPort = "      - '8080:8080'\n", string $extraCommands = ''): string
{
    return <<<YAML
# managed by ops, do not remove
name: coolify-proxy
networks:
  coolify:
    external: true
services:
  traefik:
    container_name: coolify-proxy
    image: 'traefik:v3.6'
    ports:
      - '80:80'
      - '443:443'
      - '443:443/udp'
{$apiPort}    command:
      - '--ping=true'
      - '--api.dashboard=true'
      - '--api.insecure=true' # dashboard
      - '--entrypoints.https.address=:443'
      - '--entrypoints.https.http3'
{$extraCommands}      - '--providers.docker=true'
      - '--entrypoints.https.forwardedheaders.trustedips=10.0.0.0/8'
    labels:
      - traefik.enable=false
      - coolify.managed=true

YAML;
}

function legacyHttp3CaddyConfiguration(): string
{
    return <<<'YAML'
networks:
  coolify:
    external: true
services:
  caddy:
    container_name: coolify-proxy
    image: 'lucaslorentz/caddy-docker-proxy:2.8-alpine'
    ports:
      - '80:80'
      - '443:443'
      - '443:443/udp' # QUIC
    labels:
      - coolify.managed=true
      - coolify.proxy=true

YAML;
}

describe('generated proxy configuration', function () {
    it('does not enable HTTP/3 by default', function () {
        $traefik = Yaml::parse(generateDefaultProxyConfiguration(http3CleanupServer(ProxyTypes::TRAEFIK->value)))['services']['traefik'];
        $caddy = Yaml::parse(generateDefaultProxyConfiguration(http3CleanupServer(ProxyTypes::CADDY->value)))['services']['caddy'];

        expect(config('security.proxy.http3'))->toBeFalse()
            ->and($traefik['ports'])->toBe(['80:80', '443:443'])
            ->and($traefik['command'])->not->toContain('--entrypoints.https.http3')
            ->and($caddy['ports'])->toBe(['80:80', '443:443'])
            ->and($caddy['labels'])->toContain('caddy.servers.protocols=h1 h2');
    });

    it('enables HTTP/3 when the security setting is on', function () {
        config(['security.proxy.http3' => true]);

        $traefik = Yaml::parse(generateDefaultProxyConfiguration(http3CleanupServer(ProxyTypes::TRAEFIK->value)))['services']['traefik'];
        $caddy = Yaml::parse(generateDefaultProxyConfiguration(http3CleanupServer(ProxyTypes::CADDY->value)))['services']['caddy'];

        expect($traefik['ports'])->toBe(['80:80', '443:443', '443:443/udp'])
            ->and($traefik['command'])->toContain('--entrypoints.https.http3')
            ->and($caddy['ports'])->toBe(['80:80', '443:443', '443:443/udp'])
            ->and($caddy['labels'])->not->toContain('caddy.servers.protocols=h1 h2');
    });

    it('does not carry the legacy HTTP/3 flag or insecure API over as custom commands', function () {
        $server = http3CleanupServer(ProxyTypes::TRAEFIK->value);

        $custom = extractCustomProxyCommands($server, legacyHttp3TraefikConfiguration());
        $commands = Yaml::parse(generateDefaultProxyConfiguration($server, $custom))['services']['traefik']['command'];

        expect($custom)->toBe(['--entrypoints.https.forwardedheaders.trustedips=10.0.0.0/8'])
            ->and($commands)->not->toContain('--entrypoints.https.http3')
            ->and($commands)->not->toContain('--api.insecure=true')
            ->and($commands)->toContain('--entrypoints.https.forwardedheaders.trustedips=10.0.0.0/8');
    });
});

describe('legacy configuration cleanup', function () {
    it('removes the API port, insecure API and HTTP/3 outside development and keeps the formatting', function () {
        $legacy = legacyHttp3TraefikConfiguration();

        $fixed = removeLegacyInsecureProxySettings($legacy);
        $traefik = Yaml::parse($fixed)['services']['traefik'];

        $expected = str_replace(
            ["      - '443:443/udp'\n", "      - '8080:8080'\n", "      - '--entrypoints.https.http3'\n", "'--api.insecure=true' # dashboard"],
            ['', '', '', "'--api.insecure=false' # dashboard"],
            $legacy,
        );

        expect($fixed)->toBe($expected)
            ->and($fixed)->toContain('# managed by ops, do not remove')
            ->and($traefik['ports'])->toBe(['80:80', '443:443'])
            ->and($traefik['command'])->toContain('--api.insecure=false')
            ->and($traefik['command'])->not->toContain('--api.insecure=true')
            ->and($traefik['command'])->not->toContain('--entrypoints.https.http3')
            ->and($traefik['command'])->toContain('--entrypoints.https.forwardedheaders.trustedips=10.0.0.0/8')
            ->and(removeLegacyInsecureProxySettings($fixed))->toBe($fixed);
    });

    it('keeps the API port and insecure API in development', function () {
        config(['app.env' => 'local']);

        $traefik = Yaml::parse(removeLegacyInsecureProxySettings(legacyHttp3TraefikConfiguration()))['services']['traefik'];

        expect($traefik['ports'])->toBe(['80:80', '443:443', '8080:8080'])
            ->and($traefik['command'])->toContain('--api.insecure=true')
            ->and($traefik['command'])->not->toContain('--entrypoints.https.http3');
    });

    it('keeps the API settings when the user mapped the API port differently', function (string $mapping) {
        $legacy = legacyHttp3TraefikConfiguration("      - '8080:8080'\n      - '{$mapping}'\n");

        $traefik = Yaml::parse(removeLegacyInsecureProxySettings($legacy))['services']['traefik'];

        expect($traefik['ports'])->toBe(['80:80', '443:443', '8080:8080', $mapping])
            ->and($traefik['command'])->toContain('--api.insecure=true');
    })->with(['loopback only' => '127.0.0.1:8081:8080', 'other host port' => '9090:8080']);

    it('keeps HTTP/3 when it is enabled', function () {
        config(['security.proxy.http3' => true]);

        $traefik = Yaml::parse(removeLegacyInsecureProxySettings(legacyHttp3TraefikConfiguration()))['services']['traefik'];

        expect($traefik['ports'])->toBe(['80:80', '443:443', '443:443/udp'])
            ->and($traefik['command'])->toContain('--entrypoints.https.http3')
            ->and($traefik['command'])->toContain('--api.insecure=false');
    });

    it('keeps HTTP/3 that the user configured with extra options', function () {
        $legacy = legacyHttp3TraefikConfiguration(extraCommands: "      - '--entrypoints.https.http3.advertisedport=443'\n");

        $traefik = Yaml::parse(removeLegacyInsecureProxySettings($legacy))['services']['traefik'];

        expect($traefik['ports'])->toContain('443:443/udp')
            ->and($traefik['command'])->toContain('--entrypoints.https.http3')
            ->and($traefik['ports'])->not->toContain('8080:8080');
    });

    it('removes the UDP port from a legacy Caddy configuration', function () {
        $legacy = legacyHttp3CaddyConfiguration();

        $fixed = removeLegacyInsecureProxySettings($legacy);

        expect($fixed)->toBe(str_replace("      - '443:443/udp' # QUIC\n", '', $legacy))
            ->and(Yaml::parse($fixed)['services']['caddy']['ports'])->toBe(['80:80', '443:443'])
            ->and(removeLegacyInsecureProxySettings($fixed))->toBe($fixed);
    });

    it('keeps the original when the same line also belongs to another service', function () {
        $legacy = legacyHttp3TraefikConfiguration()."  other:\n    image: nginx\n    ports:\n      - '8080:8080'\n";

        expect(removeLegacyInsecureProxySettings($legacy))->toBe($legacy);
    });

    it('does not change current or unparseable configurations', function () {
        $current = Yaml::dump(['services' => ['traefik' => ['ports' => ['80:80', '443:443'], 'command' => ['--api.insecure=false']]]], 10, 2);

        expect(removeLegacyInsecureProxySettings($current))->toBe($current)
            ->and(removeLegacyInsecureProxySettings("services:\n  traefik: [\n"))->toBe("services:\n  traefik: [\n")
            ->and(removeLegacyInsecureProxySettings('plain text'))->toBe('plain text');
    });
});

describe('applying the cleanup', function () {
    it('saves the fix when the configuration is loaded and asks for a proxy restart', function () {
        $server = http3CleanupServer(ProxyTypes::TRAEFIK->value, legacyHttp3TraefikConfiguration());
        expect($server->hasPendingProxyConfiguration())->toBeFalse();

        $configuration = GetProxyConfiguration::run($server);

        $server->refresh();
        expect($configuration)->not->toContain('8080:8080')
            ->and($configuration)->not->toContain('--api.insecure=true')
            ->and($configuration)->not->toContain('443:443/udp')
            ->and($server->proxy->last_saved_proxy_configuration)->toBe($configuration)
            ->and($server->proxy->last_saved_settings)->toBe(md5(base64_encode($configuration)))
            ->and($server->hasPendingProxyConfiguration())->toBeTrue();
    });

    it('marks a running proxy for restart when no applied settings were recorded', function () {
        $legacy = legacyHttp3CaddyConfiguration();
        $server = http3CleanupServer(ProxyTypes::CADDY->value, $legacy, applied: false);

        GetProxyConfiguration::run($server);

        $server->refresh();
        expect($server->proxy->last_applied_settings)->toBe(md5(base64_encode($legacy)))
            ->and($server->proxy->last_saved_proxy_configuration)->not->toContain('443:443/udp')
            ->and($server->hasPendingProxyConfiguration())->toBeTrue();
    });

    it('fixes saved configurations in the migration without connecting to servers', function () {
        $legacy = http3CleanupServer(ProxyTypes::TRAEFIK->value, legacyHttp3TraefikConfiguration());
        $custom = http3CleanupServer(ProxyTypes::TRAEFIK->value, $customConfiguration = legacyHttp3TraefikConfiguration("      - '127.0.0.1:9090:8080'\n", "      - '--entrypoints.https.http3.advertisedport=443'\n"));
        $none = http3CleanupServer(ProxyTypes::NONE->value, legacyHttp3TraefikConfiguration());

        $migration = require database_path('migrations/2026_10_08_150500_remove_legacy_insecure_proxy_settings.php');
        $migration->up();

        expect($migration->withinTransaction)->toBeFalse()
            ->and($legacy->fresh()->proxy->last_saved_proxy_configuration)->not->toContain('8080:8080')
            ->and($legacy->fresh()->hasPendingProxyConfiguration())->toBeTrue()
            ->and($custom->fresh()->proxy->last_saved_proxy_configuration)->toBe($customConfiguration)
            ->and($none->fresh()->proxy->last_saved_proxy_configuration)->toBe(legacyHttp3TraefikConfiguration());
        Process::assertNothingRan();
    });
});
