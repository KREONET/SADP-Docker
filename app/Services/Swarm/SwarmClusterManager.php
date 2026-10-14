<?php

namespace App\Services\Swarm;

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Actions\Proxy\StartProxy;
use App\Actions\Proxy\StopProxy;
use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds a Docker Swarm cluster from PADED servers.
 *
 * The manager node also runs workloads, so a single server is already a working cluster;
 * more servers join it as worker nodes. The manager runs the Traefik proxy as a stack and a
 * private registry that every node pulls the built images from. The server that runs PADED
 * itself (id 0) is never part of a cluster.
 */
class SwarmClusterManager
{
    public function __construct(private SwarmRemote $remote) {}

    /**
     * Reasons the server cannot become the manager of a new cluster; empty when it can.
     *
     * @return list<string>
     */
    public function managerBlockers(Server $server): array
    {
        $blockers = [];

        if ($server->id === 0) {
            $blockers[] = __('The server that runs PADED cannot join a Swarm cluster. Add another server and make it the manager.');
        }
        if ($server->isSwarm()) {
            $blockers[] = __('This server is already part of a Swarm cluster.');
        }
        if (! $server->isFunctional()) {
            $blockers[] = __('Validate the server first. It must be reachable and have Docker installed.');
        }
        if ($server->isBuildServer()) {
            $blockers[] = __('Build servers cannot join a Swarm cluster.');
        }
        if ($server->settings->is_cloudflare_tunnel) {
            $blockers[] = __('Servers reached through a Cloudflare Tunnel cannot join a Swarm cluster.');
        }
        if ($server->isRoutedThroughController()) {
            $blockers[] = __('Servers routed through the controller cannot join a Swarm cluster. Turn off routing through the controller first.');
        }
        if (! in_array($server->proxyType(), [null, ProxyTypes::NONE->value, ProxyTypes::TRAEFIK->value], true)) {
            $blockers[] = __('Swarm clusters only support the Traefik proxy. Switch the proxy to Traefik first.');
        }
        if ($server->hasDefinedResources()) {
            $blockers[] = __('Move or delete the resources on this server first. Its existing containers would stop working in Swarm mode.');
        }

        return $blockers;
    }

    /**
     * Reasons the server cannot join the manager's cluster as a worker; empty when it can.
     *
     * @return list<string>
     */
    public function workerBlockers(Server $manager, Server $server): array
    {
        $blockers = [];

        if ($server->is($manager)) {
            $blockers[] = __('The manager already runs workloads. Pick another server.');
        }
        if ($server->team_id !== $manager->team_id) {
            $blockers[] = __('The server belongs to another team.');
        }
        if ($server->id === 0) {
            $blockers[] = __('The server that runs PADED cannot join a Swarm cluster. Add another server and make it the manager.');
        }
        if ($server->isSwarm()) {
            $blockers[] = __('This server is already part of a Swarm cluster.');
        }
        if (! $server->isFunctional()) {
            $blockers[] = __('Validate the server first. It must be reachable and have Docker installed.');
        }
        if ($server->isBuildServer()) {
            $blockers[] = __('Build servers cannot join a Swarm cluster.');
        }
        if ($server->settings->is_cloudflare_tunnel) {
            $blockers[] = __('Servers reached through a Cloudflare Tunnel cannot join a Swarm cluster.');
        }
        if ($server->isRoutedThroughController()) {
            $blockers[] = __('Servers routed through the controller cannot join a Swarm cluster. Turn off routing through the controller first.');
        }
        if ($server->hasDefinedResources()) {
            $blockers[] = __('Move or delete the resources on this server first. Its existing containers would stop working in Swarm mode.');
        }

        return $blockers;
    }

    /**
     * Servers of the manager's team that can join its cluster now.
     *
     * @return Collection<int, Server>
     */
    public function workerCandidates(Server $manager): Collection
    {
        return Server::query()
            ->where('team_id', $manager->team_id)
            ->whereKeyNot($manager->id)
            ->with('settings')
            ->orderBy('name')
            ->get()
            ->filter(fn (Server $server) => $this->workerBlockers($manager, $server) === [])
            ->values();
    }

    /**
     * Worker servers in the manager's cluster.
     *
     * @return Collection<int, Server>
     */
    public function workers(Server $manager): Collection
    {
        return Server::query()
            ->where('swarm_cluster', $manager->id)
            ->whereKeyNot($manager->id)
            ->with('settings')
            ->orderBy('name')
            ->get();
    }

    /**
     * Turns the server into the manager (and first worker node) of a new cluster.
     */
    public function initialize(Server $manager, string $advertiseAddress): void
    {
        $blockers = $this->managerBlockers($manager);
        if ($blockers !== []) {
            throw new RuntimeException($blockers[0]);
        }
        if (filter_var($advertiseAddress, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException(__('Enter the IP address the other nodes use to reach this server.'));
        }

        $state = $this->remote->run($manager, [SwarmCommands::localNodeState()]);
        if ($state === 'active') {
            if ($this->remote->run($manager, [SwarmCommands::isManagerNode()]) !== 'true') {
                throw new RuntimeException(__('Docker on this server already belongs to another Swarm as a worker. Run "docker swarm leave" on it first.'));
            }
        } elseif ($state === 'inactive') {
            $this->remote->run($manager, [SwarmCommands::init($advertiseAddress)]);
        } else {
            throw new RuntimeException(__('Docker Swarm on this server is in the ":state" state. Fix it on the server and try again.', ['state' => $state]));
        }

        $this->remote->run($manager, [SwarmCommands::ensureOverlayNetwork()]);
        $this->setUpRegistry($manager);

        $usesProxy = $this->usesProxy($manager);
        if ($usesProxy) {
            StopProxy::run($manager, forceStop: false);
        }

        DB::transaction(function () use ($manager) {
            $manager->settings->forceFill(['is_swarm_manager' => true, 'is_swarm_worker' => false])->save();
            $manager->forceFill(['swarm_cluster' => $manager->id])->save();
            SwarmDocker::query()->firstOrCreate(
                ['server_id' => $manager->id, 'network' => SwarmCommands::OVERLAY_NETWORK],
                ['name' => SwarmCommands::OVERLAY_NETWORK],
            );
        });
        $manager->refresh();

        if ($usesProxy) {
            $this->restartProxy($manager);
        }
    }

    /**
     * Joins the server to the manager's cluster as a worker node.
     */
    public function addWorker(Server $manager, Server $worker): void
    {
        $this->ensureManager($manager);
        $blockers = $this->workerBlockers($manager, $worker);
        if ($blockers !== []) {
            throw new RuntimeException($blockers[0]);
        }
        if ($this->remote->run($worker, [SwarmCommands::localNodeState()]) !== 'inactive') {
            throw new RuntimeException(__('Docker on :server already belongs to a Swarm. Run "docker swarm leave" on it first.', ['server' => $worker->name]));
        }

        $token = $this->remote->run($manager, [SwarmCommands::workerJoinToken()]);
        $managerAddress = $this->remote->run($manager, [SwarmCommands::nodeAddress()]);
        if ($token === '' || $managerAddress === '') {
            throw new RuntimeException(__('Could not read the join token from the manager.'));
        }

        // Swarm publishes the proxy ports on every node, so the worker's own proxy must stop.
        $usesProxy = $this->usesProxy($worker);
        if ($usesProxy) {
            StopProxy::run($worker, forceStop: false);
        }

        $advertiseAddress = filter_var((string) $worker->ip, FILTER_VALIDATE_IP) !== false ? (string) $worker->ip : null;
        try {
            $this->remote->run($worker, [SwarmCommands::join($token, $managerAddress, $advertiseAddress)], timeout: 120);
        } catch (\Throwable $e) {
            if ($usesProxy) {
                StartProxy::run($worker, async: true, force: true);
            }

            throw $e;
        }

        DB::transaction(function () use ($manager, $worker) {
            $worker->settings->forceFill(['is_swarm_manager' => false, 'is_swarm_worker' => true])->save();
            $worker->forceFill(['swarm_cluster' => $manager->id])->save();
        });
    }

    /**
     * Moves the worker's tasks to other nodes, takes it out of the cluster and turns it back
     * into a regular server.
     */
    public function removeWorker(Server $manager, Server $worker): void
    {
        $this->ensureManager($manager);
        if ($worker->is($manager) || (int) $worker->swarm_cluster !== $manager->id || ! $worker->isSwarmWorker()) {
            throw new RuntimeException(__('The server is not a worker node of this cluster.'));
        }

        $nodeId = $this->remote->run($worker, [SwarmCommands::nodeId()], throwError: false);
        if ($nodeId === '') {
            $nodeId = (string) data_get(
                collect($this->nodes($manager))->firstWhere('address', (string) $worker->ip),
                'id',
                '',
            );
        }

        if ($nodeId !== '') {
            $this->remote->run($manager, [SwarmCommands::drainNode($nodeId)], throwError: false);
        }
        $this->remote->run($worker, [SwarmCommands::leave(force: true)], throwError: false);
        if ($nodeId !== '') {
            $this->remote->run($manager, [SwarmCommands::removeNode($nodeId)], throwError: false);
        }

        DB::transaction(function () use ($worker) {
            $worker->settings->forceFill(['is_swarm_manager' => false, 'is_swarm_worker' => false])->save();
            $worker->forceFill(['swarm_cluster' => null])->save();
        });
        $worker->refresh();

        if ($this->usesProxy($worker)) {
            StartProxy::run($worker, async: true, force: true);
        }
    }

    /**
     * Ends a cluster that has no workers and no resources left, and turns the manager back
     * into a regular server.
     */
    public function dissolve(Server $manager): void
    {
        $this->ensureManager($manager);
        if ($this->workers($manager)->isNotEmpty()) {
            throw new RuntimeException(__('Remove the worker nodes first.'));
        }
        if ($manager->hasDefinedResources()) {
            throw new RuntimeException(__('Move or delete the resources in this cluster first.'));
        }

        $this->remote->run($manager, [
            SwarmCommands::removeProxyStack(),
            ...SwarmCommands::removeRegistry(),
            SwarmCommands::leave(force: true),
        ]);

        DB::transaction(function () use ($manager) {
            $manager->settings->forceFill(['is_swarm_manager' => false, 'is_swarm_worker' => false])->save();
            $manager->forceFill(['swarm_cluster' => null])->save();
            $manager->swarmDockers()->get()->each->delete();
            if (! StandaloneDocker::query()->where('server_id', $manager->id)->exists()) {
                (new StandaloneDocker)->forceFill($manager->defaultStandaloneDockerAttributes())->saveQuietly();
            }
        });
        $manager->refresh();

        if ($this->usesProxy($manager)) {
            $this->restartProxy($manager);
        }
    }

    /**
     * Recreates the registry with a new password, for example after its login was lost.
     */
    public function repairRegistry(Server $manager): void
    {
        $this->ensureManager($manager);
        $this->setUpRegistry($manager);
    }

    /**
     * Nodes reported by the manager, matched to PADED servers by address.
     *
     * @return list<array{id: string, hostname: string, role: string, availability: string, state: string, address: string, cpus: float, memory_bytes: int, leader: bool, server: ?Server}>
     */
    public function nodes(Server $manager): array
    {
        $this->ensureManager($manager);
        $output = $this->remote->run($manager, [SwarmCommands::listNodes()]);
        $servers = Server::query()->where('swarm_cluster', $manager->id)->get()->keyBy(fn (Server $server) => (string) $server->ip);

        return collect(preg_split('/\R/', $output) ?: [])
            ->map(fn (string $line) => explode('|', trim($line)))
            ->filter(fn (array $fields) => count($fields) >= 8 && $fields[0] !== '')
            ->map(fn (array $fields) => [
                'id' => $fields[0],
                'hostname' => $fields[1],
                'role' => $fields[2],
                'availability' => $fields[3],
                'state' => $fields[4],
                'address' => $fields[5],
                'cpus' => round(((int) $fields[6]) / 1_000_000_000, 1),
                'memory_bytes' => (int) $fields[7],
                'leader' => ($fields[8] ?? '') === 'true',
                'server' => $servers->get($fields[5]),
            ])
            ->values()
            ->all();
    }

    public static function registryImageName(string $applicationUuid): string
    {
        return SwarmCommands::REGISTRY_ADDRESS.'/'.$applicationUuid;
    }

    private function ensureManager(Server $manager): void
    {
        if (! $manager->isSwarmManager()) {
            throw new RuntimeException(__('This server is not a Swarm manager.'));
        }
    }

    private function setUpRegistry(Server $manager): void
    {
        $nodeId = $this->remote->run($manager, [SwarmCommands::nodeId()]);
        if ($nodeId === '') {
            throw new RuntimeException(__('Could not read the Swarm node ID of the manager.'));
        }

        $this->remote->run($manager, SwarmCommands::setUpRegistry($nodeId, Str::random(40)), timeout: 300);
    }

    private function usesProxy(Server $server): bool
    {
        return ! in_array($server->proxyType(), [null, ProxyTypes::NONE->value], true);
    }

    /**
     * Writes a proxy configuration for the server's current mode (Swarm stack or container)
     * and starts the proxy with it.
     */
    private function restartProxy(Server $server): void
    {
        $configuration = GetProxyConfiguration::run($server, forceRegenerate: true);
        SaveProxyConfiguration::run($server, $configuration);
        StartProxy::run($server, async: true, force: true);
    }
}
