<?php

namespace App\Livewire\Server\Proxy;

use App\Actions\Server\ConfigureControllerRouting;
use App\Models\ControllerIngressRoute;
use App\Models\Server;
use App\Services\ControllerIngress\ControllerIngress;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * On a worker: routes its traffic through the controller's proxy instead of its own.
 * On the controller: lists the workers it receives traffic for and syncs their relays.
 */
class ControllerRouting extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public bool $isEnabled = false;

    public ?string $internalIp = null;

    public function mount(): void
    {
        $this->authorize('view', $this->server);
        $this->syncFromSettings();
    }

    public function save(): void
    {
        try {
            $this->authorize('update', $this->server);
            $this->internalIp = filled($this->internalIp) ? trim($this->internalIp) : null;
            $this->validate([
                'isEnabled' => ['boolean'],
                'internalIp' => [$this->isEnabled ? 'required' : 'nullable', 'ip'],
            ]);

            $wasEnabled = $this->server->isRoutedThroughController();
            ConfigureControllerRouting::run($this->server, $this->isEnabled, $this->internalIp);
            auditLog('ui.server.controller_routing_updated', [
                'team_id' => $this->server->team_id,
                'server_uuid' => $this->server->uuid,
                'enabled' => $this->isEnabled,
            ]);
            $this->server->refresh();
            $this->syncFromSettings();

            $this->dispatch('success', match (true) {
                $this->isEnabled && ! $wasEnabled => __('The controller now receives the traffic of this server. Its proxy stopped. Redeploy its applications and restart its services to publish them on the internal IP.'),
                ! $this->isEnabled && $wasEnabled => __('This server uses its own proxy again. Redeploy its applications and restart its services.'),
                default => __('Saved.'),
            });
            $this->redirectRoute('server.proxy', ['server_uuid' => $this->server->uuid], navigate: true);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function syncRoutes(): void
    {
        try {
            $this->authorize('update', $this->server);
            ControllerIngress::sync();
            $this->dispatch('success', __('Routes synced.'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function syncFromSettings(): void
    {
        $this->isEnabled = $this->server->isRoutedThroughController();
        $this->internalIp = $this->server->settings->internal_ip;
    }

    public function render()
    {
        $isController = $this->server->id === 0;

        return view('livewire.server.proxy.controller-routing', [
            'isController' => $isController,
            'blockers' => $isController ? [] : ConfigureControllerRouting::blockers($this->server),
            'routedServers' => $isController
                ? Server::ownedByCurrentTeam()->whereRelation('settings', 'is_routed_through_controller', true)->get()
                : collect(),
            'routeCounts' => $isController
                ? ControllerIngressRoute::query()->where('is_active', true)->selectRaw('server_id, count(*) as total')->groupBy('server_id')->pluck('total', 'server_id')
                : collect(),
            'activeRoutes' => $isController ? 0 : ControllerIngressRoute::query()->where('server_id', $this->server->id)->where('is_active', true)->count(),
            'portRange' => ControllerIngress::HOST_PORT_MIN.'-'.ControllerIngress::HOST_PORT_MAX,
        ]);
    }
}
