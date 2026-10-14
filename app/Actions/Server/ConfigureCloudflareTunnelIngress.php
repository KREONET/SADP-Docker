<?php

namespace App\Actions\Server;

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use App\Services\ProxyPortParser;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Runs or removes cloudflared next to the server's proxy so public traffic arrives through a
 * Cloudflare Tunnel: Cloudflare terminates TLS and cloudflared forwards to `http://coolify-proxy:80`.
 * The tunnel's public hostnames are managed in the Cloudflare dashboard (token-based tunnel).
 *
 * Unlike the per-server "Cloudflare Tunnel" page, which carries SSH, this carries the traffic of
 * applications and services.
 */
class ConfigureCloudflareTunnelIngress
{
    use AsAction;

    public const CONTAINER_NAME = 'coolify-cloudflared-ingress';

    public const IMAGE = 'cloudflare/cloudflared:latest';

    private const STOPPED_PROXY_STATUSES = ['exited', 'stopped', 'stopping', 'dead'];

    /**
     * @return bool Whether a proxy restart was queued.
     */
    public function handle(Server $server): bool
    {
        $blockers = self::blockers($server);
        if ($blockers !== []) {
            throw new RuntimeException($blockers[0]);
        }

        $settings = $server->settings;
        $enabled = (bool) $settings->is_cloudflare_tunnel_ingress_enabled;
        $directory = self::directory();

        if ($enabled) {
            $token = (string) $settings->cloudflare_tunnel_ingress_token;
            if (self::tunnelIdFromToken($token) === null) {
                throw new RuntimeException(__('The Cloudflare Tunnel token is not valid.'));
            }
            $compose = base64_encode(self::compose($server));
            $environment = base64_encode("TUNNEL_TOKEN={$token}\n");
            instant_remote_process([
                "mkdir -p {$directory}",
                "echo '{$compose}' | base64 -d | tee {$directory}/docker-compose.yml > /dev/null",
                "(umask 077 && echo '{$environment}' | base64 -d > {$directory}/.env)",
                "docker compose -p coolify-cloudflared-ingress -f {$directory}/docker-compose.yml pull",
                "docker compose -p coolify-cloudflared-ingress -f {$directory}/docker-compose.yml up -d --remove-orphans",
            ], $server);
        } else {
            instant_remote_process([
                "if [ -f {$directory}/docker-compose.yml ]; then docker compose -p coolify-cloudflared-ingress -f {$directory}/docker-compose.yml down --remove-orphans; fi",
                'docker rm -f '.self::CONTAINER_NAME.' >/dev/null 2>&1 || true',
                "rm -f {$directory}/docker-compose.yml {$directory}/.env",
            ], $server, false);
        }

        // The dashboard route follows the tunnel (HTTP only behind it).
        if ($server->isLocalhost()) {
            $server->setupDynamicProxyConfiguration();
        }

        $configuration = GetProxyConfiguration::run($server);
        ProxyPortParser::fromConfiguration($configuration);
        SaveProxyConfiguration::run($server, applyCloudflareTunnelToProxyConfiguration($server, $configuration));

        $restart = ! $this->proxyIsStopped($server);
        if ($restart) {
            RestartProxyJob::dispatch($server);
        }

        return $restart;
    }

    /**
     * @return array<int, string>
     */
    public static function blockers(Server $server): array
    {
        $blockers = [];
        if ($server->isRoutedThroughController()) {
            $blockers[] = __('This server has no proxy of its own. Set up the tunnel on the controller, which receives its traffic.');
        }
        if ($server->isSwarm()) {
            $blockers[] = __('Cloudflare Tunnel traffic is not available for Swarm servers.');
        }
        if (! in_array($server->proxyType(), [ProxyTypes::TRAEFIK->value, ProxyTypes::CADDY->value], true)) {
            $blockers[] = __('Cloudflare Tunnel traffic needs the Traefik or Caddy proxy.');
        }

        return $blockers;
    }

    /**
     * The tunnel ID inside a token-based tunnel token (base64 JSON with the keys a, t, and s).
     */
    public static function tunnelIdFromToken(?string $token): ?string
    {
        $token = trim((string) $token);
        if ($token === '' || preg_match('/^[A-Za-z0-9+\/=_-]+$/', $token) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        if (! is_array($payload) || ! is_string($payload['t'] ?? null) || blank($payload['a'] ?? null) || blank($payload['s'] ?? null)) {
            return null;
        }

        return preg_match('/^[0-9a-f-]{36}$/i', $payload['t']) === 1 ? strtolower($payload['t']) : null;
    }

    public static function directory(): string
    {
        return base_configuration_dir().'/proxy/cloudflared-ingress';
    }

    private static function compose(Server $server): string
    {
        $network = $server->standaloneDockers()->orderBy('id')->value('network');
        $network = is_string($network) && isUsableDockerNetworkName($network) ? $network : 'coolify';

        return implode("\n", [
            'name: coolify-cloudflared-ingress',
            'services:',
            '  cloudflared:',
            '    container_name: '.self::CONTAINER_NAME,
            '    image: '.self::IMAGE,
            '    restart: '.RESTART_MODE,
            '    command: tunnel --no-autoupdate run',
            '    env_file:',
            '      - .env',
            '    networks:',
            "      - {$network}",
            'networks:',
            "  {$network}:",
            '    external: true',
            '',
        ]);
    }

    private function proxyIsStopped(Server $server): bool
    {
        return (bool) $server->proxy->get('force_stop')
            || in_array($server->proxy->get('status'), self::STOPPED_PROXY_STATUSES, true);
    }
}
