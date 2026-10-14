<?php

use App\Actions\Proxy\StartProxy;
use App\Jobs\SyncControllerIngressJob;
use App\Livewire\Server\Proxy\ControllerRouting;
use App\Models\Application;
use App\Models\ControllerIngressRoute;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\ControllerIngress\ControllerIngress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Cache::flush();
    Once::flush();
    $this->team = Team::factory()->create();
});

function ingressServer(Team $team, array $attributes = [], array $settings = [], string $proxy = 'TRAEFIK'): Server
{
    $privateKey = PrivateKey::query()->where('team_id', $team->id)->first() ?? PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(array_merge(['team_id' => $team->id, 'private_key_id' => $privateKey->id, 'proxy' => ['type' => $proxy]], $attributes));
    $server->settings->forceFill(array_merge(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false], $settings))->save();
    Server::flushIdentityMap();

    return $server->fresh();
}

function ingressController(Team $team, string $proxy = 'TRAEFIK'): Server
{
    return ingressServer($team, ['id' => 0, 'ip' => 'host.docker.internal'], [], $proxy);
}

function ingressWorker(Team $team, string $internalIp = '10.0.0.12'): Server
{
    return ingressServer($team, [], ['is_routed_through_controller' => true, 'internal_ip' => $internalIp]);
}

function ingressApplication(Server $server): Application
{
    $project = Project::factory()->create(['team_id' => $server->team_id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();

    return Application::factory()->createOne([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'fqdn' => 'https://app.example.com',
        'ports_exposes' => '3000',
    ]);
}

/**
 * @return array<string, mixed>
 */
function ingressCompose(string $containerName = 'app-1', array $extraLabels = []): array
{
    return [
        'services' => [
            $containerName => [
                'container_name' => $containerName,
                'expose' => ['3000'],
                'labels' => array_merge([
                    'traefik.enable=true',
                    'traefik.http.routers.http-0-app.rule=Host(`app.example.com`) && PathPrefix(`/`)',
                    'traefik.http.routers.http-0-app.entryPoints=http',
                    'traefik.http.routers.http-0-app.service=http-0-app',
                    'traefik.http.services.http-0-app.loadbalancer.server.port=3000',
                    'coolify.managed=true',
                    'coolify.applicationId=1',
                ], $extraLabels),
            ],
            'db' => ['image' => 'postgres:16'],
        ],
    ];
}

describe('publishing routed containers on a worker', function () {
    it('publishes routed containers on the internal IP and records an inactive route', function () {
        ingressController($this->team);
        $worker = ingressWorker($this->team);
        $application = ingressApplication($worker);

        $compose = ControllerIngress::prepareCompose($worker, $application, 'application-1', ingressCompose());

        expect($compose['services']['app-1']['ports'])->toBe(['10.0.0.12:41000:3000'])
            ->and($compose['services']['db'])->not->toHaveKey('ports');

        $route = ControllerIngressRoute::sole();
        expect($route->is_active)->toBeFalse()
            ->and($route->ports)->toBe(['3000' => 41000])
            ->and($route->service_name)->toBe('app-1')
            ->and($route->resource->is($application))->toBeTrue()
            ->and($route->labels)->toHaveKey('traefik.http.routers.http-0-app.rule');
    });

    it('keeps the host port of a container and gives a new container the next free port', function () {
        ingressController($this->team);
        $worker = ingressWorker($this->team);
        $application = ingressApplication($worker);

        ControllerIngress::prepareCompose($worker, $application, 'application-1', ingressCompose('app-1'));
        $again = ControllerIngress::prepareCompose($worker, $application, 'application-1', ingressCompose('app-1'));
        $next = ControllerIngress::prepareCompose($worker, $application, 'application-1', ingressCompose('app-2'));

        expect($again['services']['app-1']['ports'])->toBe(['10.0.0.12:41000:3000'])
            ->and($next['services']['app-2']['ports'])->toBe(['10.0.0.12:41001:3000']);
    });

    it('brackets an IPv6 internal address', function () {
        ingressController($this->team);
        $worker = ingressWorker($this->team, 'fd00::12');

        $compose = ControllerIngress::prepareCompose($worker, ingressApplication($worker), 'application-1', ingressCompose());

        expect($compose['services']['app-1']['ports'])->toBe(['[fd00::12]:41000:3000']);
    });

    it('leaves the compose file of a server with its own proxy unchanged', function () {
        $server = ingressServer($this->team);
        $compose = ingressCompose();

        expect(ControllerIngress::prepareCompose($server, ingressApplication($server), 'application-1', $compose))->toBe($compose)
            ->and(ControllerIngressRoute::count())->toBe(0);
    });

    it('requires an internal IP', function () {
        ingressController($this->team);
        $worker = ingressWorker($this->team, '');

        ControllerIngress::prepareCompose($worker, ingressApplication($worker), 'application-1', ingressCompose());
    })->throws(RuntimeException::class, 'internal IP');

    it('moves traffic to the new container and drops the routes of older ones on activation', function () {
        Queue::fake();
        ingressController($this->team);
        $worker = ingressWorker($this->team);
        $application = ingressApplication($worker);
        ControllerIngress::prepareCompose($worker, $application, 'application-1', ingressCompose('app-1'), activate: true);
        ControllerIngress::prepareCompose($worker, $application, 'application-1', ingressCompose('app-2'));

        expect(ControllerIngressRoute::where('container_name', 'app-2')->sole()->is_active)->toBeFalse();

        ControllerIngress::activate($worker, 'application-1', ['app-2']);

        expect(ControllerIngressRoute::pluck('container_name')->all())->toBe(['app-2'])
            ->and(ControllerIngressRoute::sole()->is_active)->toBeTrue();
        Queue::assertPushed(SyncControllerIngressJob::class);
    });

    it('makes implicit upstream ports explicit so the relay knows them', function () {
        $labels = ControllerIngress::withExplicitPorts([
            'caddy_0' => 'https://app.example.com',
            'caddy_0.handle_path.0_reverse_proxy' => '{{upstreams}}',
            'traefik.enable' => 'true',
            'traefik.http.routers.http-0-app.rule' => 'Host(`app.example.com`)',
        ], 8080);

        expect($labels['caddy_0.handle_path.0_reverse_proxy'])->toBe('{{upstreams 8080}}')
            ->and($labels['traefik.http.routers.http-0-app.service'])->toBe('http-0-app')
            ->and($labels['traefik.http.services.http-0-app.loadbalancer.server.port'])->toBe('8080')
            ->and(ControllerIngress::routedContainerPorts($labels))->toBe([8080]);
    });

    it('reads labels in the map form of a compose file', function () {
        expect(ControllerIngress::normalizeLabels(['traefik.enable' => true, 'caddy_0' => 'a.example.com']))
            ->toBe(['traefik.enable' => 'true', 'caddy_0' => 'a.example.com'])
            ->and(ControllerIngress::hasRoutingLabels(['traefik.enable' => 'false']))->toBeFalse();
    });
});

describe('relays on the controller', function () {
    it('builds one relay that balances an application across routed workers', function () {
        $controller = ingressController($this->team);
        $first = ingressWorker($this->team, '10.0.0.12');
        $second = ingressWorker($this->team, '10.0.0.13');
        $application = ingressApplication($first);
        $application->additional_servers()->attach($second->id, ['standalone_docker_id' => StandaloneDocker::where('server_id', $second->id)->value('id')]);
        Queue::fake();
        ControllerIngress::prepareCompose($first, $application, 'application-1', ingressCompose('app-a'), activate: true, serviceName: 'application');
        ControllerIngress::prepareCompose($second, $application, 'application-1', ingressCompose('app-b', ['traefik.docker.network=coolify-worker']), activate: true, serviceName: 'application');

        $compose = ControllerIngress::relayCompose($controller);

        expect($compose['services'])->toHaveCount(1);
        $relay = array_values($compose['services'])[0];
        $nginx = base64_decode(str($relay['command'][0])->between('echo ', ' |')->value());
        expect($relay['networks'])->toBe(['coolify'])
            ->and($relay['expose'])->toBe(['3000'])
            ->and($relay['labels'])->toContain('traefik.http.services.http-0-app.loadbalancer.server.port=3000')
            ->and($relay['labels'])->toContain('paded.relay=true')
            ->and(collect($relay['labels'])->filter(fn ($label) => str_starts_with($label, 'coolify.'))->all())->toBe([])
            ->and($nginx)->toContain('server 10.0.0.12:41000')
            ->and($nginx)->toContain('server 10.0.0.13:41000')
            ->and($nginx)->toContain('listen 3000;');
    });

    it('points Caddy and Traefik network labels at the controller network', function () {
        expect(ControllerIngress::relayLabels([
            'caddy_ingress_network' => 'service-uuid',
            'traefik.docker.network' => 'service-uuid',
            'coolify.serviceId' => '4',
        ], 'coolify'))->toBe(['caddy_ingress_network=coolify', 'traefik.docker.network=coolify']);
    });

    it('starts the relays on the controller and removes them when nothing is routed', function () {
        Process::fake();
        Queue::fake();
        ingressController($this->team);
        $worker = ingressWorker($this->team);
        $application = ingressApplication($worker);
        ControllerIngress::prepareCompose($worker, $application, 'application-1', ingressCompose(), activate: true);

        ControllerIngress::sync();
        Process::assertRan(fn ($process) => str_contains($process->command, 'docker compose -p paded-relays') && str_contains($process->command, 'up -d --remove-orphans'));

        $application->delete();
        expect(ControllerIngressRoute::count())->toBe(0);

        ControllerIngress::sync();
        Process::assertRan(fn ($process) => str_contains($process->command, 'down --remove-orphans'));
    });

    it('drops routes of a server that is no longer routed through the controller', function () {
        Process::fake();
        Queue::fake();
        ingressController($this->team);
        $worker = ingressWorker($this->team);
        ControllerIngress::prepareCompose($worker, ingressApplication($worker), 'application-1', ingressCompose(), activate: true);
        $worker->settings->forceFill(['is_routed_through_controller' => false])->save();

        ControllerIngress::sync();

        expect(ControllerIngressRoute::count())->toBe(0);
    });
});

describe('routed workers', function () {
    it('runs no proxy on a routed worker and sends its traffic to the controller', function () {
        $controller = ingressController($this->team);
        $worker = ingressWorker($this->team);

        expect($worker->proxySet())->toBeFalse()
            ->and($worker->isProxyShouldRun())->toBeFalse()
            ->and(StartProxy::run($worker))->toBe('OK')
            ->and($worker->trafficEntryServer()->is($controller))->toBeTrue()
            ->and(proxyAuthPanelUrlForServer($worker))->toBe(rtrim((string) config('constants.proxy_auth.internal_url'), '/'));
    });

    it('never routes the controller through itself', function () {
        $controller = ingressServer($this->team, ['id' => 0], ['is_routed_through_controller' => true]);

        expect($controller->isRoutedThroughController())->toBeFalse();
    });

    it('does not mix routed workers and servers with their own proxy in one application', function () {
        ingressController($this->team);
        $worker = ingressWorker($this->team);
        $standalone = ingressServer($this->team);
        $application = ingressApplication($worker);

        expect($application->proxyMismatchReason($standalone))->toContain('routed through the controller')
            ->and($application->proxyMismatchReason(ingressWorker($this->team, '10.0.0.20')))->toBeNull();
    });
});

describe('controller routing settings', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
        makePlatformAdmin($this->owner);
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);
        Process::fake();
        Queue::fake();
    });

    it('routes a worker through the controller and stops its proxy', function () {
        ingressController($this->team, 'CADDY');
        $worker = ingressServer($this->team);

        Livewire::test(ControllerRouting::class, ['server' => $worker])
            ->set('isEnabled', true)
            ->set('internalIp', '10.0.0.12')
            ->call('save')
            ->assertHasNoErrors();

        $worker = $worker->fresh();
        expect($worker->isRoutedThroughController())->toBeTrue()
            ->and($worker->settings->internal_ip)->toBe('10.0.0.12')
            ->and($worker->proxyType())->toBe('CADDY');
        Process::assertRan(fn ($process) => str_contains($process->command, 'docker rm -f coolify-proxy'));
    });

    it('requires a valid internal IP', function () {
        ingressController($this->team);
        $worker = ingressServer($this->team);

        Livewire::test(ControllerRouting::class, ['server' => $worker])
            ->set('isEnabled', true)
            ->set('internalIp', 'not-an-ip')
            ->call('save')
            ->assertHasErrors(['internalIp']);

        expect($worker->fresh()->isRoutedThroughController())->toBeFalse();
    });

    it('turns routing off, drops the routes, and starts the worker proxy again', function () {
        ingressController($this->team);
        $worker = ingressWorker($this->team);
        ControllerIngressRoute::create([
            'server_id' => $worker->id, 'resource_key' => 'application-1', 'service_name' => 'app', 'container_name' => 'app',
            'ports' => ['3000' => 41000], 'labels' => ['traefik.enable' => 'true'], 'is_active' => true,
        ]);

        Livewire::test(ControllerRouting::class, ['server' => $worker])
            ->set('isEnabled', false)
            ->call('save')
            ->assertHasNoErrors();

        expect($worker->fresh()->isRoutedThroughController())->toBeFalse()
            ->and(ControllerIngressRoute::count())->toBe(0);
        Queue::assertPushed(SyncControllerIngressJob::class);
    });

    it('lists routed workers on the controller', function () {
        $controller = ingressController($this->team);
        $worker = ingressWorker($this->team);

        Livewire::test(ControllerRouting::class, ['server' => $controller])
            ->assertSee($worker->name)
            ->assertSee('10.0.0.12');
    });

    it('relays generated relay config through compose yaml', function () {
        $controller = ingressController($this->team);
        $worker = ingressWorker($this->team);
        ControllerIngress::prepareCompose($worker, ingressApplication($worker), 'application-1', ingressCompose('app-1', ['traefik.http.middlewares.x.redirectregex.replacement=$${1}']), activate: true);

        $yaml = Yaml::dump(ControllerIngress::relayCompose($controller), 10, 2);

        // Compose escaping of the worker's labels is kept, so the relay label means the same.
        expect($yaml)->toContain('$${1}');
    });
});
