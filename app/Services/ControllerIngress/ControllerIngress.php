<?php

namespace App\Services\ControllerIngress;

use App\Jobs\SyncControllerIngressJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\ControllerIngressRoute;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Routes the resources of worker servers through the controller's proxy.
 *
 * A worker that is routed through the controller runs no proxy. Its routed containers publish their
 * ports on the worker's internal IP. On the controller, one small relay container per routed
 * container carries the container's proxy labels and forwards TCP to the worker. The controller's
 * Traefik or Caddy reads those labels like the labels of a local container, so domains, HTTPS,
 * redirects, basic auth, and exposure modes all work unchanged. When an application runs on several
 * routed workers, its relay balances between them.
 */
class ControllerIngress
{
    /** Host ports on the workers' internal IPs that routed containers are published on. */
    public const HOST_PORT_MIN = 41000;

    public const HOST_PORT_MAX = 48999;

    public const RELAY_PROJECT = 'paded-relays';

    public const RELAY_IMAGE = 'nginx:stable-alpine';

    public const RELAY_LABEL = 'paded.relay=true';

    public static function applicationResourceKey(Application $application, int $pullRequestId = 0): string
    {
        return $pullRequestId === 0
            ? "application-{$application->id}"
            : "application-{$application->id}-pr-{$pullRequestId}";
    }

    public static function serviceResourceKey(Service $service): string
    {
        return "service-{$service->id}";
    }

    /**
     * Publishes the routed containers of a Compose file on the worker's internal IP and records their
     * routes. Inactive routes take no traffic until activate() runs; pass $activate for resources
     * that replace their containers in place.
     *
     * Routes of a resource with the same service name share one relay, which balances between the
     * workers. Pass $serviceName when the Compose service key changes per deployment or server (an
     * application's key is its generated container name).
     *
     * @param  array<string, mixed>  $compose
     * @return array<string, mixed>
     */
    public static function prepareCompose(Server $server, Model $resource, string $resourceKey, array $compose, bool $activate = false, ?string $serviceName = null): array
    {
        if (! $server->isRoutedThroughController() || ! is_array($compose['services'] ?? null)) {
            return $compose;
        }

        $internalIp = self::internalIp($server);
        $containerNames = [];

        $logicalServiceName = $serviceName;
        Cache::lock("controller-ingress-ports:{$server->id}", 30)->block(30, function () use ($server, $resource, $resourceKey, $internalIp, $activate, $logicalServiceName, &$compose, &$containerNames) {
            foreach ($compose['services'] as $serviceName => $service) {
                if (! is_array($service)) {
                    continue;
                }
                $labels = self::normalizeLabels($service['labels'] ?? []);
                if (! self::hasRoutingLabels($labels)) {
                    continue;
                }

                $labels = self::withExplicitPorts($labels, firstDockerComposeServicePort($service) ?? 80);
                $containerName = (string) ($service['container_name'] ?? $serviceName);
                $route = ControllerIngressRoute::firstOrNew(['server_id' => $server->id, 'container_name' => $containerName]);
                $existingPorts = $route->exists ? (array) $route->ports : [];

                $ports = [];
                foreach (self::routedContainerPorts($labels) as $containerPort) {
                    $ports[(string) $containerPort] = (int) ($existingPorts[(string) $containerPort] ?? self::allocateHostPort($server, $route->id, $ports));
                }

                $route->fill([
                    'resource_key' => $resourceKey,
                    'service_name' => $logicalServiceName ?? (string) $serviceName,
                    'resource_type' => $resource->getMorphClass(),
                    'resource_id' => $resource->getKey(),
                    'ports' => $ports,
                    'labels' => $labels,
                    'is_active' => $activate || ($route->exists && $route->is_active),
                ])->save();

                $published = is_array($service['ports'] ?? null) ? $service['ports'] : [];
                foreach ($ports as $containerPort => $hostPort) {
                    $published[] = self::formatAddress($internalIp, $hostPort).":{$containerPort}";
                }
                $compose['services'][$serviceName]['ports'] = array_values(array_unique($published));
                $containerNames[] = $containerName;
            }
        });

        if ($activate) {
            self::activate($server, $resourceKey, $containerNames);
        }

        return $compose;
    }

    /**
     * Names of the containers in a Compose file that the controller routes to.
     *
     * @param  array<string, mixed>  $compose
     * @return array<int, string>
     */
    public static function routedContainerNames(array $compose): array
    {
        $names = [];
        foreach ((array) ($compose['services'] ?? []) as $serviceName => $service) {
            if (is_array($service) && self::hasRoutingLabels(self::normalizeLabels($service['labels'] ?? []))) {
                $names[] = (string) ($service['container_name'] ?? $serviceName);
            }
        }

        return $names;
    }

    /**
     * Moves the traffic of a resource on a worker to the given containers. Routes of the resource's
     * other containers on that worker (an older deployment, a removed Compose service) are dropped.
     *
     * @param  array<int, string>  $containerNames
     */
    public static function activate(Server $server, string $resourceKey, array $containerNames, bool $sync = false): void
    {
        if (! $server->isRoutedThroughController()) {
            return;
        }

        $routes = ControllerIngressRoute::query()->where('server_id', $server->id)->where('resource_key', $resourceKey);
        (clone $routes)->whereIn('container_name', $containerNames)->update(['is_active' => true]);
        (clone $routes)->whereNotIn('container_name', $containerNames)->delete();

        $sync ? self::sync() : SyncControllerIngressJob::dispatch();
    }

    /**
     * Drops all routes of a deleted resource.
     */
    public static function forget(Model $resource): void
    {
        $deleted = ControllerIngressRoute::query()
            ->where('resource_type', $resource->getMorphClass())
            ->where('resource_id', $resource->getKey())
            ->delete();

        if ($deleted > 0) {
            SyncControllerIngressJob::dispatch();
        }
    }

    /**
     * Drops all routes of a worker, for example when it stops being routed through the controller.
     */
    public static function forgetServer(Server $server): void
    {
        ControllerIngressRoute::query()->where('server_id', $server->id)->delete();
        SyncControllerIngressJob::dispatch();
    }

    /**
     * Brings the relay containers on the controller in line with the active routes.
     */
    public static function sync(): void
    {
        $controller = Server::find(0);
        if (! $controller || ! $controller->isFunctional()) {
            return;
        }

        self::pruneStaleRoutes();

        $compose = self::relayCompose($controller);
        $directory = self::relayDirectory();
        $file = "{$directory}/docker-compose.yml";
        $project = self::RELAY_PROJECT;

        if ($compose === null) {
            $commands = [
                "if [ -f {$file} ]; then docker compose -p {$project} -f {$file} down --remove-orphans; fi",
                "rm -f {$file}",
            ];
        } else {
            $base64 = base64_encode(Yaml::dump($compose, 10, 2));
            $commands = [
                "mkdir -p {$directory}",
                "echo '{$base64}' | base64 -d | tee {$file} > /dev/null",
                "docker compose -p {$project} -f {$file} up -d --remove-orphans",
            ];
        }

        instant_remote_process($commands, $controller);
    }

    /**
     * The Compose project of the relays, or null when nothing is routed.
     *
     * @return array<string, mixed>|null
     */
    public static function relayCompose(Server $controller): ?array
    {
        $network = self::controllerNetwork($controller);
        $groups = ControllerIngressRoute::query()
            ->with('server.settings')
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (ControllerIngressRoute $route) => "{$route->resource_key}|{$route->service_name}")
            ->sortKeys();

        $services = [];
        foreach ($groups as $groupKey => $routes) {
            $upstreams = [];
            foreach ($routes as $route) {
                $internalIp = $route->server?->settings?->internal_ip;
                if (blank($internalIp)) {
                    continue;
                }
                foreach ((array) $route->ports as $containerPort => $hostPort) {
                    $upstreams[(int) $containerPort][] = self::formatAddress($internalIp, (int) $hostPort);
                }
            }
            if ($upstreams === []) {
                continue;
            }
            ksort($upstreams);

            $name = 'relay-'.substr(hash('sha256', (string) $groupKey), 0, 16);
            $nginx = base64_encode(self::relayNginxConfiguration($upstreams));
            $services[$name] = [
                'image' => self::RELAY_IMAGE,
                'container_name' => "paded-{$name}",
                'restart' => RESTART_MODE,
                'networks' => [$network],
                'expose' => array_map('strval', array_keys($upstreams)),
                // The newest route of the group carries the current labels.
                'labels' => array_merge(self::relayLabels((array) $routes->first()->labels, $network), [self::RELAY_LABEL]),
                'entrypoint' => ['/bin/sh', '-c'],
                'command' => ["echo {$nginx} | base64 -d > /etc/nginx/nginx.conf && exec nginx -g 'daemon off;'"],
            ];
        }

        if ($services === []) {
            return null;
        }

        return [
            'name' => self::RELAY_PROJECT,
            'services' => $services,
            'networks' => [$network => ['external' => true]],
        ];
    }

    /**
     * nginx stream configuration that forwards each container port to the workers that run it.
     *
     * @param  array<int, array<int, string>>  $upstreams
     */
    public static function relayNginxConfiguration(array $upstreams): string
    {
        $lines = ['worker_processes 1;', 'events { worker_connections 4096; }', 'stream {'];
        foreach ($upstreams as $containerPort => $addresses) {
            $addresses = array_values(array_unique($addresses));
            sort($addresses);
            $lines[] = "    upstream port_{$containerPort} {";
            foreach ($addresses as $address) {
                $lines[] = "        server {$address} max_fails=1 fail_timeout=10s;";
            }
            $lines[] = '    }';
            $lines[] = "    server { listen {$containerPort}; proxy_connect_timeout 5s; proxy_pass port_{$containerPort}; }";
        }
        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    /**
     * The routed container's labels for its relay: the controller network replaces the worker's
     * network, and PADED's own labels stay on the worker so the relay is never taken for the resource.
     *
     * @param  array<string, string>  $labels
     * @return array<int, string>
     */
    public static function relayLabels(array $labels, string $network): array
    {
        $result = [];
        foreach ($labels as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, 'coolify.') || $key === 'paded.relay') {
                continue;
            }
            if ($key === 'caddy_ingress_network' || $key === 'traefik.docker.network') {
                $value = $network;
            }
            $result[] = "{$key}={$value}";
        }
        sort($result);

        return $result;
    }

    /**
     * Compose labels as a key => value map, from either the list or the map form.
     *
     * @return array<string, string>
     */
    public static function normalizeLabels(mixed $labels): array
    {
        if (! is_array($labels)) {
            return [];
        }

        $result = [];
        foreach ($labels as $key => $value) {
            if (is_int($key)) {
                if (! is_string($value) || ! str_contains($value, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $value, 2);
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $result[trim((string) $key)] = (string) $value;
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $labels
     */
    public static function hasRoutingLabels(array $labels): bool
    {
        foreach ($labels as $key => $value) {
            if (strtolower($key) === 'traefik.enable' && strtolower(trim($value)) === 'true') {
                return true;
            }
            if (preg_match('/^caddy(_\d+)?$/', $key) === 1 && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Makes every upstream name its port. The relay listens on the container's ports, so a Traefik
     * router without a service or a bare Caddy `{{upstreams}}` would otherwise pick a port the relay
     * does not know.
     *
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    public static function withExplicitPorts(array $labels, int $defaultPort): array
    {
        foreach ($labels as $key => $value) {
            if (preg_match('/^caddy(_\d+)?\..*reverse_proxy$/', $key) === 1) {
                $labels[$key] = (string) preg_replace('/\{\{\s*upstreams\s*\}\}/', "{{upstreams {$defaultPort}}}", $value);
            }
        }

        $routers = [];
        foreach (array_keys($labels) as $key) {
            if (preg_match('/^traefik\.http\.routers\.([^.]+)\./i', $key, $matches) === 1) {
                $routers[$matches[1]] = true;
            }
        }
        foreach (array_keys($routers) as $router) {
            $serviceKey = "traefik.http.routers.{$router}.service";
            if (! isset($labels[$serviceKey])) {
                $labels[$serviceKey] = $router;
            }
            $portKey = "traefik.http.services.{$labels[$serviceKey]}.loadbalancer.server.port";
            if (! isset($labels[$portKey]) && ! self::definesService($labels, $labels[$serviceKey])) {
                $labels[$portKey] = (string) $defaultPort;
            }
        }

        return $labels;
    }

    /**
     * Container ports the controller has to reach.
     *
     * @param  array<string, string>  $labels
     * @return array<int, int>
     */
    public static function routedContainerPorts(array $labels): array
    {
        $ports = [];
        foreach ($labels as $key => $value) {
            if (preg_match('/^traefik\.http\.services\.[^.]+\.loadbalancer\.server\.port$/i', $key) === 1 && ctype_digit(trim($value))) {
                $ports[] = (int) trim($value);
            }
            if (preg_match_all('/\{\{\s*upstreams\s+(\d+)\s*\}\}/', $value, $matches) > 0) {
                array_push($ports, ...array_map('intval', $matches[1]));
            }
        }
        $ports = array_values(array_unique(array_filter($ports, fn (int $port) => $port >= 1 && $port <= 65535)));
        sort($ports);

        return $ports;
    }

    public static function internalIp(Server $server): string
    {
        $internalIp = trim((string) $server->settings?->internal_ip);
        if (filter_var($internalIp, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException(__('Set the internal IP of :server before deploying to it. The controller reaches the server on that address.', ['server' => $server->name]));
        }

        return $internalIp;
    }

    public static function relayDirectory(): string
    {
        return base_configuration_dir().'/proxy/controller-ingress';
    }

    private static function controllerNetwork(Server $controller): string
    {
        $network = $controller->standaloneDockers()->orderBy('id')->value('network');

        return is_string($network) && isUsableDockerNetworkName($network) ? $network : 'coolify';
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function definesService(array $labels, string $service): bool
    {
        $prefix = strtolower("traefik.http.services.{$service}.");
        foreach (array_keys($labels) as $key) {
            if (str_starts_with(strtolower($key), $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, int>  $reserved  host ports already taken by the route being prepared
     */
    private static function allocateHostPort(Server $server, ?int $routeId, array $reserved): int
    {
        $used = array_flip(array_map('intval', $reserved));
        ControllerIngressRoute::query()
            ->where('server_id', $server->id)
            ->when($routeId, fn ($query) => $query->whereKeyNot($routeId))
            ->pluck('ports')
            ->each(function ($ports) use (&$used) {
                foreach ((array) $ports as $hostPort) {
                    $used[(int) $hostPort] = true;
                }
            });

        for ($port = self::HOST_PORT_MIN; $port <= self::HOST_PORT_MAX; $port++) {
            if (! isset($used[$port])) {
                return $port;
            }
        }

        throw new RuntimeException(__('No free port is left on :server for routing through the controller.', ['server' => $server->name]));
    }

    private static function formatAddress(string $ip, int $port): string
    {
        return str_contains($ip, ':') ? "[{$ip}]:{$port}" : "{$ip}:{$port}";
    }

    /**
     * Drops routes whose resource is gone, moved to another server, or whose server is no longer
     * routed through the controller.
     */
    private static function pruneStaleRoutes(): void
    {
        ControllerIngressRoute::query()->with(['server.settings', 'resource'])->get()
            ->each(function (ControllerIngressRoute $route) {
                $server = $route->server;
                $resource = $route->resource;
                $stale = ! $server
                    || ! $server->isRoutedThroughController()
                    || ($route->resource_type !== null && ! $resource)
                    || ($resource && ! self::resourceRunsOn($resource, $server));
                if ($stale) {
                    Log::info('Dropping a stale controller ingress route', ['route_id' => $route->id, 'container' => $route->container_name]);
                    $route->delete();
                }
            });
    }

    private static function resourceRunsOn(Model $resource, Server $server): bool
    {
        if ($resource instanceof ApplicationPreview) {
            $resource = $resource->application;
        }
        if ($resource instanceof Application) {
            return $resource->destination?->server_id === $server->id
                || $resource->additional_servers()->wherePivot('server_id', $server->id)->exists();
        }
        if ($resource instanceof Service) {
            return $resource->destination?->server_id === $server->id;
        }

        return true;
    }
}
