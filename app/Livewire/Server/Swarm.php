<?php

namespace App\Livewire\Server;

use App\Models\Server;
use App\Services\Swarm\SwarmClusterManager;
use App\Services\Swarm\SwarmCommands;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Builds a Docker Swarm cluster from this server: it becomes the manager (which also runs
 * workloads) and other servers of the team join it as worker nodes.
 */
class Swarm extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $parameters = [];

    public string $advertiseAddress = '';

    public ?string $workerServerUuid = null;

    /**
     * @var list<array{id: string, hostname: string, role: string, availability: string, state: string, address: string, cpus: float, memory_bytes: int, leader: bool, server_name: ?string, server_uuid: ?string}>
     */
    public array $nodes = [];

    public ?string $nodesError = null;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->parameters = get_route_parameters();
            $this->advertiseAddress = filter_var((string) $this->server->ip, FILTER_VALIDATE_IP) !== false ? (string) $this->server->ip : '';
        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
    }

    public function initialize()
    {
        try {
            $this->authorize('update', $this->server);
            $this->validate(['advertiseAddress' => 'required|ip']);

            $this->cluster()->initialize($this->server, $this->advertiseAddress);
            $this->server->refresh();
            $this->loadNodes();
            $this->dispatch('success', __('Swarm cluster created. This server is the manager and also runs workloads.'));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function addWorker()
    {
        try {
            $this->authorize('update', $this->server);
            $this->validate(['workerServerUuid' => 'required|string']);
            $worker = Server::ownedByCurrentTeam()->whereUuid($this->workerServerUuid)->firstOrFail();
            $this->authorize('update', $worker);

            $this->cluster()->addWorker($this->server, $worker);
            $this->workerServerUuid = null;
            $this->loadNodes();
            $this->dispatch('success', __(':server joined the cluster as a worker node.', ['server' => $worker->name]));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function removeWorker(string $workerUuid, $password)
    {
        try {
            $this->authorize('update', $this->server);
            if (! verifyPasswordConfirmation($password, $this)) {
                return __('The provided password is incorrect.');
            }
            $worker = Server::ownedByCurrentTeam()->whereUuid($workerUuid)->firstOrFail();
            $this->authorize('update', $worker);

            $this->cluster()->removeWorker($this->server, $worker);
            $this->loadNodes();
            $this->dispatch('success', __(':server left the cluster. Its tasks moved to the other nodes.', ['server' => $worker->name]));

            return true;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function dissolve($password)
    {
        try {
            $this->authorize('update', $this->server);
            if (! verifyPasswordConfirmation($password, $this)) {
                return __('The provided password is incorrect.');
            }

            $this->cluster()->dissolve($this->server);
            $this->server->refresh();
            $this->nodes = [];
            $this->dispatch('success', __('The Swarm cluster was removed. This server is a regular server again.'));

            return true;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function repairRegistry()
    {
        try {
            $this->authorize('update', $this->server);
            $this->cluster()->repairRegistry($this->server);
            $this->dispatch('success', __('The cluster registry was set up again.'));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadNodes(): void
    {
        $this->nodes = [];
        $this->nodesError = null;
        if (! $this->server->isSwarmManager()) {
            return;
        }

        try {
            $this->authorize('view', $this->server);
            $this->nodes = collect($this->cluster()->nodes($this->server))
                ->map(function (array $node) {
                    $server = $node['server'];
                    unset($node['server']);

                    return [
                        ...$node,
                        'server_name' => $server?->name,
                        'server_uuid' => $server?->uuid,
                    ];
                })
                ->all();
        } catch (\Throwable $e) {
            $this->nodesError = __('Could not read the cluster nodes from the manager.');
            report($e);
        }
    }

    public function render()
    {
        $isManager = (bool) $this->server->isSwarmManager();
        $isWorker = (bool) $this->server->isSwarmWorker();

        return view('livewire.server.swarm', [
            'isManager' => $isManager,
            'isWorker' => $isWorker,
            'managerBlockers' => $isManager || $isWorker ? [] : $this->cluster()->managerBlockers($this->server),
            'workers' => $isManager ? $this->cluster()->workers($this->server) : collect(),
            'workerCandidates' => $isManager ? $this->cluster()->workerCandidates($this->server) : collect(),
            'manager' => $isWorker && $this->server->swarm_cluster
                ? Server::ownedByCurrentTeam()->whereKey($this->server->swarm_cluster)->first()
                : null,
            'requiredPorts' => SwarmCommands::REQUIRED_PORTS,
            'registryAddress' => SwarmCommands::REGISTRY_ADDRESS,
        ]);
    }

    private function cluster(): SwarmClusterManager
    {
        return app(SwarmClusterManager::class);
    }
}
