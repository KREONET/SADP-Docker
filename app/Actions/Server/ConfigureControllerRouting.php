<?php

namespace App\Actions\Server;

use App\Actions\Proxy\StartProxy;
use App\Actions\Proxy\StopProxy;
use App\Enums\ProxyTypes;
use App\Jobs\SyncControllerIngressJob;
use App\Models\Server;
use App\Services\ControllerIngress\ControllerIngress;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Turns routing through the controller on or off for a worker on the internal network.
 *
 * On: the worker's own proxy stops, and its resources publish their ports on the internal IP at
 * their next deployment. The controller's proxy receives their traffic.
 * Off: the routes are dropped and the worker's own proxy starts again.
 */
class ConfigureControllerRouting
{
    use AsAction;

    public function handle(Server $server, bool $enabled, ?string $internalIp = null): void
    {
        $internalIp = filled($internalIp) ? trim($internalIp) : null;

        if (! $enabled) {
            $this->disable($server, $internalIp);

            return;
        }

        $blockers = self::blockers($server);
        if ($blockers !== []) {
            throw new RuntimeException($blockers[0]);
        }
        if ($internalIp === null || filter_var($internalIp, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException(__('Enter the IP address of the server on the internal network.'));
        }

        $wasRouted = $server->isRoutedThroughController();
        $previousIp = $server->settings->internal_ip;
        $server->settings->forceFill([
            'is_routed_through_controller' => true,
            'internal_ip' => $internalIp,
        ])->save();
        $server->refresh();

        if (! $wasRouted) {
            // Proxy labels follow the controller's proxy, so the worker takes its proxy type.
            $server->proxy->set('type', Server::find(0)->proxyType());
            $server->save();
            StopProxy::run($server, forceStop: false);
        } elseif ($previousIp !== $internalIp) {
            SyncControllerIngressJob::dispatch();
        }
    }

    /**
     * Reasons why the server cannot be routed through the controller.
     *
     * @return array<int, string>
     */
    public static function blockers(Server $server): array
    {
        $blockers = [];
        if ($server->id === 0) {
            $blockers[] = __('The controller receives traffic with its own proxy.');
        }
        if ($server->isSwarm()) {
            $blockers[] = __('Swarm servers route traffic through the Swarm manager instead.');
        }
        if (! $server->canHostResources()) {
            $blockers[] = __('Build servers do not run resources, so there is no traffic to route.');
        }

        $controller = Server::find(0);
        if (! $controller || ! in_array($controller->proxyType(), [ProxyTypes::TRAEFIK->value, ProxyTypes::CADDY->value], true)) {
            $blockers[] = __('The controller must run Traefik or Caddy to receive traffic for other servers.');
        }

        return $blockers;
    }

    private function disable(Server $server, ?string $internalIp): void
    {
        $wasRouted = $server->isRoutedThroughController();
        $server->settings->forceFill([
            'is_routed_through_controller' => false,
            'internal_ip' => $internalIp ?? $server->settings->internal_ip,
        ])->save();
        $server->refresh();

        if (! $wasRouted) {
            return;
        }

        ControllerIngress::forgetServer($server);
        if ($server->proxySet()) {
            $server->proxy->set('force_stop', false);
            $server->save();
            StartProxy::dispatch($server);
        }
    }
}
