<?php

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Actions\Proxy\StartProxy;
use App\Actions\Proxy\StopProxy;
use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Destination\New\Docker;
use App\Livewire\Server\Swarm;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Swarm\SwarmClusterManager;
use App\Services\Swarm\SwarmRemote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Replaces SSH with a recorder. Each response is keyed by a fragment of the command and may
 * be a callable that receives the server.
 *
 * @param  array<string, string|callable(Server): string>  $responses
 */
function fakeSwarmRemote(array $responses = []): SwarmRemote
{
    $remote = new class extends SwarmRemote
    {
        /** @var list<array{server: int, script: string}> */
        public array $calls = [];

        /** @var array<string, string|callable(Server): string> */
        public array $responses = [];

        public function run(Server $server, array $commands, bool $throwError = true, ?int $timeout = null): string
        {
            $script = implode("\n", $commands);
            $this->calls[] = ['server' => $server->id, 'script' => $script];
            foreach ($this->responses as $fragment => $response) {
                if (str_contains($script, $fragment)) {
                    return is_callable($response) ? $response($server) : $response;
                }
            }

            return '';
        }

        public function scriptFor(Server $server): string
        {
            return collect($this->calls)->where('server', $server->id)->pluck('script')->implode("\n");
        }
    };
    $remote->responses = $responses;
    app()->instance(SwarmRemote::class, $remote);

    return $remote;
}

function makeSwarmReadyServer(Team $team, array $attributes = [], ?string $proxyType = 'NONE'): Server
{
    $server = Server::factory()->create(['team_id' => $team->id, ...$attributes]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $server->proxy->set('type', $proxyType);
    $server->save();

    return $server->fresh();
}

function makeSwarmApplication(Team $team, Server $server): Application
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    $destination = $server->swarmDockers()->first() ?? StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerfile',
    ]);
}

function swarmNodeResponses(): array
{
    return [
        'LocalNodeState' => 'inactive',
        'ControlAvailable' => 'true',
        '.Swarm.NodeID' => 'manager-node',
        '.Swarm.NodeAddr' => '10.0.0.1',
        'join-token' => 'SWMTKN-1-token',
    ];
}

beforeEach(function () {
    InstanceSettings::query()->forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    makePlatformAdmin($this->user);
    $this->server = makeSwarmReadyServer($this->team, ['ip' => '10.0.0.1']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

describe('creating a cluster', function () {
    test('the server becomes the manager, runs the registry and gets a Swarm destination', function () {
        $remote = fakeSwarmRemote(swarmNodeResponses());

        app(SwarmClusterManager::class)->initialize($this->server, '10.0.0.1');

        $server = $this->server->fresh();
        expect($server->settings->is_swarm_manager)->toBeTruthy()
            ->and($server->settings->is_swarm_worker)->toBeFalsy()
            ->and((int) $server->swarm_cluster)->toBe($server->id)
            ->and(SwarmDocker::query()->where('server_id', $server->id)->where('network', 'coolify-overlay')->exists())->toBeTrue()
            ->and($remote->scriptFor($server))
            ->toContain("docker swarm init --advertise-addr '10.0.0.1'")
            ->toContain('docker network create --driver overlay --attachable')
            ->toContain("docker service create --detach --name coolify-registry --constraint 'node.id==manager-node'");
    });

    test('a running Traefik proxy is restarted as a Swarm stack', function () {
        $server = makeSwarmReadyServer($this->team, ['ip' => '10.0.0.5'], 'TRAEFIK');
        fakeSwarmRemote(swarmNodeResponses());
        StopProxy::shouldRun()->once();
        GetProxyConfiguration::shouldRun()->once()
            ->withArgs(fn (Server $target, bool $forceRegenerate) => $target->is($server) && $forceRegenerate && $target->isSwarmManager())
            ->andReturn('services: {}');
        SaveProxyConfiguration::shouldRun()->once();
        StartProxy::shouldRun()->once();

        app(SwarmClusterManager::class)->initialize($server, '10.0.0.5');
    });

    test('an existing Swarm on the server is reused when it is a manager', function () {
        $remote = fakeSwarmRemote([...swarmNodeResponses(), 'LocalNodeState' => 'active']);

        app(SwarmClusterManager::class)->initialize($this->server, '10.0.0.1');

        expect($remote->scriptFor($this->server))->not->toContain('docker swarm init')
            ->and($this->server->fresh()->settings->is_swarm_manager)->toBeTruthy();
    });

    test('a server that is a worker of another Swarm is refused', function () {
        fakeSwarmRemote([...swarmNodeResponses(), 'LocalNodeState' => 'active', 'ControlAvailable' => 'false']);

        expect(fn () => app(SwarmClusterManager::class)->initialize($this->server, '10.0.0.1'))
            ->toThrow(RuntimeException::class, 'already belongs to another Swarm as a worker');
        expect($this->server->fresh()->settings->is_swarm_manager)->toBeFalsy();
    });

    test('servers that cannot become a manager are refused before anything runs', function (Closure $prepare, string $message) {
        $remote = fakeSwarmRemote(swarmNodeResponses());
        $server = $prepare($this->team, $this->server);

        expect(fn () => app(SwarmClusterManager::class)->initialize($server, '10.0.0.1'))
            ->toThrow(RuntimeException::class, $message);
        expect($remote->calls)->toBe([]);
    })->with([
        'server running PADED' => [fn (Team $team) => tap(Server::factory()->create(['id' => 0, 'team_id' => $team->id]), function (Server $server) {
            $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
        })->fresh(), 'The server that runs PADED cannot join'],
        'unvalidated server' => [fn (Team $team, Server $server) => tap($server, fn () => $server->settings()->update(['is_usable' => false]))->fresh(), 'Validate the server first'],
        'caddy proxy' => [fn (Team $team) => makeSwarmReadyServer($team, [], 'CADDY'), 'only support the Traefik proxy'],
        'server with resources' => [function (Team $team, Server $server) {
            makeSwarmApplication($team, $server);

            return $server;
        }, 'Move or delete the resources on this server first'],
    ]);

    test('the advertise address must be an IP address', function () {
        $remote = fakeSwarmRemote(swarmNodeResponses());

        expect(fn () => app(SwarmClusterManager::class)->initialize($this->server, 'manager.example.com; reboot'))
            ->toThrow(RuntimeException::class, 'Enter the IP address');
        expect($remote->calls)->toBe([]);
    });
});

describe('worker nodes', function () {
    beforeEach(function () {
        fakeSwarmRemote(swarmNodeResponses());
        app(SwarmClusterManager::class)->initialize($this->server, '10.0.0.1');
        $this->manager = $this->server->fresh();
        $this->worker = makeSwarmReadyServer($this->team, ['ip' => '10.0.0.2', 'name' => 'worker-a']);
    });

    test('a server joins with the manager token and address', function () {
        $remote = fakeSwarmRemote(swarmNodeResponses());

        app(SwarmClusterManager::class)->addWorker($this->manager, $this->worker);

        $worker = $this->worker->fresh();
        expect($remote->scriptFor($worker))
            ->toContain("docker swarm join --token 'SWMTKN-1-token' --advertise-addr '10.0.0.2' '10.0.0.1:2377'")
            ->and($worker->settings->is_swarm_worker)->toBeTruthy()
            ->and((int) $worker->swarm_cluster)->toBe($this->manager->id)
            ->and(app(SwarmClusterManager::class)->workers($this->manager)->pluck('id')->all())->toBe([$worker->id]);
    });

    test('only eligible servers of the same team are offered as workers', function () {
        $otherTeam = Team::factory()->create();
        makeSwarmReadyServer($otherTeam, ['name' => 'other-team']);
        $busy = makeSwarmReadyServer($this->team, ['name' => 'busy']);
        makeSwarmApplication($this->team, $busy);

        expect(app(SwarmClusterManager::class)->workerCandidates($this->manager)->pluck('name')->all())->toBe(['worker-a'])
            ->and(app(SwarmClusterManager::class)->workerBlockers($this->manager, Server::query()->where('name', 'other-team')->first()))
            ->toContain('The server belongs to another team.');
    });

    test('a server already in a Swarm is not joined again', function () {
        fakeSwarmRemote([...swarmNodeResponses(), 'LocalNodeState' => 'active']);

        expect(fn () => app(SwarmClusterManager::class)->addWorker($this->manager, $this->worker))
            ->toThrow(RuntimeException::class, 'already belongs to a Swarm');
        expect($this->worker->fresh()->settings->is_swarm_worker)->toBeFalsy();
    });

    test('a failed join starts the worker proxy again', function () {
        $worker = makeSwarmReadyServer($this->team, ['ip' => '10.0.0.3'], 'TRAEFIK');
        fakeSwarmRemote([
            ...swarmNodeResponses(),
            'docker swarm join' => fn () => throw new RuntimeException('connection refused'),
        ]);
        StopProxy::shouldRun()->once();
        StartProxy::shouldRun()->once()->withArgs(fn (Server $target) => $target->is($worker));

        expect(fn () => app(SwarmClusterManager::class)->addWorker($this->manager, $worker))
            ->toThrow(RuntimeException::class, 'connection refused');
        expect($worker->fresh()->settings->is_swarm_worker)->toBeFalsy();
    });

    test('removing a worker drains it, leaves the Swarm and makes it a regular server', function () {
        app(SwarmClusterManager::class)->addWorker($this->manager, $this->worker);
        $remote = fakeSwarmRemote([...swarmNodeResponses(), '.Swarm.NodeID' => fn (Server $server) => "node-{$server->id}"]);

        app(SwarmClusterManager::class)->removeWorker($this->manager, $this->worker->fresh());

        $worker = $this->worker->fresh();
        expect($remote->scriptFor($this->manager))
            ->toContain("docker node update --availability drain 'node-{$worker->id}'")
            ->toContain("docker node rm --force 'node-{$worker->id}'")
            ->and($remote->scriptFor($worker))->toContain('docker swarm leave --force')
            ->and($worker->settings->is_swarm_worker)->toBeFalsy()
            ->and($worker->swarm_cluster)->toBeNull();
    });

    test('nodes are read from the manager and matched to servers', function () {
        app(SwarmClusterManager::class)->addWorker($this->manager, $this->worker);
        fakeSwarmRemote([
            'docker node inspect' => "m1|manager-host|manager|active|ready|10.0.0.1|4000000000|8589934592|true\nw1|worker-host|worker|active|down|10.0.0.2|2000000000|4294967296|",
        ]);

        $nodes = app(SwarmClusterManager::class)->nodes($this->manager);

        expect($nodes)->toHaveCount(2)
            ->and($nodes[0])->toMatchArray(['id' => 'm1', 'role' => 'manager', 'cpus' => 4.0, 'memory_bytes' => 8589934592, 'leader' => true])
            ->and($nodes[0]['server']->id)->toBe($this->manager->id)
            ->and($nodes[1])->toMatchArray(['hostname' => 'worker-host', 'state' => 'down', 'leader' => false])
            ->and($nodes[1]['server']->id)->toBe($this->worker->id);
    });

    test('the cluster cannot be removed while it has workers', function () {
        app(SwarmClusterManager::class)->addWorker($this->manager, $this->worker);

        expect(fn () => app(SwarmClusterManager::class)->dissolve($this->manager->fresh()))
            ->toThrow(RuntimeException::class, 'Remove the worker nodes first.');
    });

    test('removing an empty cluster makes the manager a regular server again', function () {
        $remote = fakeSwarmRemote(swarmNodeResponses());

        app(SwarmClusterManager::class)->dissolve($this->manager);

        $manager = $this->manager->fresh();
        expect($remote->scriptFor($manager))
            ->toContain('docker stack rm coolify-proxy')
            ->toContain('docker service rm coolify-registry')
            ->toContain('docker swarm leave --force')
            ->and($manager->settings->is_swarm_manager)->toBeFalsy()
            ->and($manager->swarm_cluster)->toBeNull()
            ->and($manager->swarmDockers()->exists())->toBeFalse()
            ->and(StandaloneDocker::query()->where('server_id', $manager->id)->exists())->toBeTrue();
    });
});

describe('swarm page', function () {
    test('every regular server shows the Swarm menu', function () {
        $this->get(route('server.show', ['server_uuid' => $this->server->uuid]))
            ->assertSuccessful()
            ->assertSee(route('server.swarm', ['server_uuid' => $this->server->uuid]), false);
    });

    test('platform admins create a cluster from the page', function () {
        fakeSwarmRemote(swarmNodeResponses());

        Livewire::test(Swarm::class, ['server_uuid' => $this->server->uuid])
            ->assertSet('advertiseAddress', '10.0.0.1')
            ->assertSee('Create Swarm cluster')
            ->call('initialize')
            ->assertDispatched('success');

        expect($this->server->fresh()->settings->is_swarm_manager)->toBeTruthy();
    });

    test('team members who are not platform admins cannot change the cluster', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member, ['role' => 'owner']);
        $this->actingAs($member);
        session(['currentTeam' => $this->team]);
        $remote = fakeSwarmRemote(swarmNodeResponses());

        Livewire::test(Swarm::class, ['server_uuid' => $this->server->uuid])
            ->call('initialize')
            ->assertDispatched('error');

        expect($remote->calls)->toBe([])
            ->and($this->server->fresh()->settings->is_swarm_manager)->toBeFalsy();
    });

    test('a server of another team cannot be added as a worker', function () {
        fakeSwarmRemote(swarmNodeResponses());
        app(SwarmClusterManager::class)->initialize($this->server, '10.0.0.1');
        $foreign = makeSwarmReadyServer(Team::factory()->create(), ['name' => 'foreign']);
        $remote = fakeSwarmRemote(swarmNodeResponses());

        Livewire::test(Swarm::class, ['server_uuid' => $this->server->uuid])
            ->set('workerServerUuid', $foreign->uuid)
            ->call('addWorker')
            ->assertStatus(404);

        expect($remote->scriptFor($foreign))->toBe('')
            ->and($foreign->fresh()->settings->is_swarm_worker)->toBeFalsy();
    });

    test('removing the cluster requires the password', function () {
        fakeSwarmRemote(swarmNodeResponses());
        app(SwarmClusterManager::class)->initialize($this->server, '10.0.0.1');
        $remote = fakeSwarmRemote(swarmNodeResponses());

        Livewire::test(Swarm::class, ['server_uuid' => $this->server->uuid])
            ->call('dissolve', 'wrong-password')
            ->assertReturned('The provided password is incorrect.');

        expect($remote->scriptFor($this->server))->not->toContain('docker swarm leave')
            ->and($this->server->fresh()->settings->is_swarm_manager)->toBeTruthy();
    });

    test('worker servers point to their manager', function () {
        fakeSwarmRemote(swarmNodeResponses());
        app(SwarmClusterManager::class)->initialize($this->server, '10.0.0.1');
        $worker = makeSwarmReadyServer($this->team, ['ip' => '10.0.0.2', 'name' => 'worker-a']);
        app(SwarmClusterManager::class)->addWorker($this->server->fresh(), $worker);

        Livewire::test(Swarm::class, ['server_uuid' => $worker->uuid])
            ->assertSee('Worker node')
            ->assertSee($this->server->name);
    });
});

describe('deployments', function () {
    function makeSwarmRegistryJob(Application $application, Server $server): array
    {
        $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
        $job = $reflection->newInstanceWithoutConstructor();
        $queue = Mockery::mock(ApplicationDeploymentQueue::class);
        $queue->shouldReceive('addLogEntry');
        foreach (['application' => $application, 'mainServer' => $server, 'application_deployment_queue' => $queue] as $name => $value) {
            $reflection->getProperty($name)->setValue($job, $value);
        }

        return [$job, $reflection];
    }

    test('applications on a cluster push to the cluster registry without saving it', function () {
        fakeSwarmRemote(swarmNodeResponses());
        app(SwarmClusterManager::class)->initialize($this->server, '10.0.0.1');
        $manager = $this->server->fresh();
        $application = makeSwarmApplication($this->team, $manager);
        [$job, $reflection] = makeSwarmRegistryJob($application, $manager);

        $reflection->getMethod('useSwarmClusterRegistry')->invoke($job);
        $application->save();

        expect($application->docker_registry_image_name)->toBe("127.0.0.1:5000/{$application->uuid}")
            ->and($application->fresh()->docker_registry_image_name)->toBeNull();
    });

    test('an application registry image and regular servers are left alone', function () {
        $regular = makeSwarmReadyServer($this->team);
        $application = makeSwarmApplication($this->team, $regular);
        [$job, $reflection] = makeSwarmRegistryJob($application, $regular);
        $reflection->getMethod('useSwarmClusterRegistry')->invoke($job);
        expect($application->docker_registry_image_name)->toBeNull();

        fakeSwarmRemote(swarmNodeResponses());
        app(SwarmClusterManager::class)->initialize($this->server, '10.0.0.1');
        $manager = $this->server->fresh();
        $own = makeSwarmApplication($this->team, $manager);
        $own->update(['docker_registry_image_name' => 'registry.example.com/app']);
        [$job, $reflection] = makeSwarmRegistryJob($own->fresh(), $manager);
        $reflection->getMethod('useSwarmClusterRegistry')->invoke($job);

        expect($reflection->getProperty('application')->getValue($job)->docker_registry_image_name)->toBe('registry.example.com/app');
    });

    test('new applications may run on the manager node', function () {
        fakeSwarmRemote(swarmNodeResponses());
        app(SwarmClusterManager::class)->initialize($this->server, '10.0.0.1');

        expect(makeSwarmApplication($this->team, $this->server->fresh())->settings->fresh()->is_swarm_only_worker_nodes)->toBeFalsy();
    });
});

test('destination creation derives swarm mode from the existing server', function () {
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_swarm_manager' => true,
    ]);

    Livewire::test(Docker::class, ['server_id' => (string) $this->server->id])
        ->set('name', 'Existing Swarm Network')
        ->set('network', 'existing-overlay')
        ->call('submit')
        ->assertHasNoErrors();

    expect(SwarmDocker::query()
        ->whereBelongsTo($this->server)
        ->where('network', 'existing-overlay')
        ->exists())->toBeTrue();
});
