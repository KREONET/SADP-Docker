<?php

use App\Livewire\Project\Application\General as ApplicationGeneral;
use App\Livewire\Project\Database\Postgresql\General as PostgresqlGeneral;
use App\Livewire\Project\New\DockerCompose;
use App\Livewire\Project\Service\StackForm;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Support\ContainerPrivilegePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const HOST_ACCESS_SAFE_COMPOSE = "services:\n  web:\n    image: nginx:alpine\n";
const HOST_ACCESS_DOCKER_SOCKET_COMPOSE = "services:\n  web:\n    image: nginx:alpine\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n";

beforeEach(function () {
    $this->withoutVite();
    $this->withoutDefer();
    Queue::fake();
    Process::fake();
    config()->set('app.maintenance.store', 'array');
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true, 'is_dns_validation_enabled' => false]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => 'alpine',
        'ports_exposes' => '80',
        'redirect' => 'both',
    ])->fresh();

    $this->service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => HOST_ACCESS_SAFE_COMPOSE,
        'docker_compose' => HOST_ACCESS_SAFE_COMPOSE,
    ]);
});

function hostAccessApiToken(User $user, Team $team): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'host-access-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

describe('run option detection', function () {
    it('flags options that give the container host access', function (string $options, string $expected) {
        expect(ContainerPrivilegePolicy::dangerousRunOptions($options))->toContain($expected);
    })->with([
        'privileged' => ['--privileged', '--privileged'],
        'cap-add with equals' => ['--cap-add=SYS_ADMIN', '--cap-add SYS_ADMIN'],
        'cap-add with space' => ['--init --cap-add NET_ADMIN', '--cap-add NET_ADMIN'],
        'device' => ['--device /dev/sda', '--device /dev/sda'],
        'unconfined seccomp' => ['--security-opt seccomp=unconfined', '--security-opt seccomp=unconfined'],
        'host pid' => ['--pid=host', '--pid host'],
        'host network' => ['--network host', '--network host'],
        'short host volume' => ['-v /:/host', '--volume /:/host'],
        'docker socket volume' => ['--volume=/var/run/docker.sock:/var/run/docker.sock', '--volume /var/run/docker.sock:/var/run/docker.sock'],
        'bind mount' => ['--mount type=bind,source=/,target=/host', '--mount type=bind,source=/,target=/host'],
        'option hidden in a quoted value' => ['--entrypoint "sh --privileged"', '--privileged'],
    ]);

    it('accepts options that do not reach the host', function () {
        expect(ContainerPrivilegePolicy::dangerousRunOptions(
            '--init --cap-drop ALL --security-opt no-new-privileges --shm-size 1g --ulimit nofile=1024:2048 --hostname app --gpus all -v data:/data --network mynet --mount type=volume,source=data,target=/data'
        ))->toBe([])
            ->and(ContainerPrivilegePolicy::dangerousRunOptions(null))->toBe([]);
    });
});

describe('compose detection', function () {
    it('flags compose settings that give containers host access', function (string $compose, string $expected) {
        expect(ContainerPrivilegePolicy::dangerousComposeSettings($compose, ['/data/coolify/services/abc']))->toContain($expected);
    })->with([
        'privileged' => ["services:\n  web:\n    image: x\n    privileged: true\n", 'web: privileged'],
        'cap_add' => ["services:\n  web:\n    image: x\n    cap_add: [SYS_ADMIN]\n", 'web: cap_add SYS_ADMIN'],
        'devices' => ["services:\n  web:\n    image: x\n    devices: ['/dev/sda:/dev/sda']\n", 'web: devices /dev/sda:/dev/sda'],
        'host network' => ["services:\n  web:\n    image: x\n    network_mode: host\n", 'web: network_mode: host'],
        'host pid' => ["services:\n  web:\n    image: x\n    pid: host\n", 'web: pid: host'],
        'docker socket' => [HOST_ACCESS_DOCKER_SOCKET_COMPOSE, 'web: volume /var/run/docker.sock'],
        'host root' => ["services:\n  web:\n    image: x\n    volumes: ['/:/host']\n", 'web: volume /'],
        'relative escape' => ["services:\n  web:\n    image: x\n    volumes: ['./../../source:/x']\n", 'web: volume ./../../source'],
        'absolute path climbing out of the resource directory' => ["services:\n  web:\n    image: x\n    volumes: ['/data/coolify/services/abc/../../source:/x']\n", 'web: volume /data/coolify/services/abc/../../source'],
        'long syntax bind' => ["services:\n  web:\n    image: x\n    volumes:\n      - type: bind\n        source: /etc\n        target: /host-etc\n", 'web: volume /etc'],
        'env_file outside' => ["services:\n  web:\n    image: x\n    env_file: /data/coolify/source/.env\n", 'web: env_file /data/coolify/source/.env'],
        'secret file' => ["services:\n  web:\n    image: x\nsecrets:\n  key:\n    file: /root/.ssh/id_rsa\n", 'secrets.key: file /root/.ssh/id_rsa'],
        'local driver bind' => ["services:\n  web:\n    image: x\nvolumes:\n  root:\n    driver_opts:\n      type: none\n      o: bind\n      device: /\n", 'volumes.root: device /'],
        'volumes_from another container' => ["services:\n  web:\n    image: x\n    volumes_from: ['container:coolify']\n", 'web: volumes_from container:coolify'],
    ]);

    it('accepts named volumes, the resource directory and read-only time zone files', function () {
        $compose = <<<'YAML'
services:
  web:
    image: x
    env_file: .env
    security_opt: [no-new-privileges:true]
    volumes:
      - data:/data
      - ./config:/config
      - /data/coolify/services/abc/files:/files
      - /etc/localtime:/etc/localtime:ro
volumes:
  data: {}
YAML;

        expect(ContainerPrivilegePolicy::dangerousComposeSettings($compose, ['/data/coolify/services/abc']))->toBe([]);
    });
});

describe('save rules', function () {
    it('lets platform admins save anything and others keep unchanged values', function () {
        $admin = makePlatformAdmin(User::factory()->create());

        expect(ContainerPrivilegePolicy::runOptionsViolation(null, '--privileged', $admin))->toBeNull()
            ->and(ContainerPrivilegePolicy::runOptionsViolation('--privileged', ' --privileged ', $this->user))->toBeNull()
            ->and(ContainerPrivilegePolicy::runOptionsViolation('--privileged', '--init', $this->user))->toBeNull()
            ->and(ContainerPrivilegePolicy::runOptionsViolation('--privileged', '--privileged --init', $this->user))->toContain('--privileged')
            ->and(ContainerPrivilegePolicy::runOptionsViolation(null, '--privileged', null))->toContain('--privileged');
    });

    it('does not treat a member of the root team as a platform admin', function () {
        $member = makePlatformAdmin(User::factory()->create(), 'member');

        expect(fn () => ContainerPrivilegePolicy::ensureRunOptionsAllowed(null, '--privileged', $member))
            ->toThrow(ValidationException::class);
    });
});

describe('livewire', function () {
    it('refuses dangerous run options on an application for a team owner who is not a platform admin', function () {
        Livewire::test(ApplicationGeneral::class, ['application' => $this->application])
            ->set('customDockerRunOptions', '--privileged')
            ->call('submit')
            ->assertDispatched('error', fn (string $event, array $params): bool => str_contains($params[0], '--privileged'))
            ->assertNotDispatched('success');

        expect($this->application->refresh()->custom_docker_run_options)->toBeNull()
            ->and(AuditEvent::query()->where('event', 'container.host_access.change')->where('result', 'denied')->exists())->toBeTrue();
    });

    it('lets a platform admin save dangerous run options on an application', function () {
        makePlatformAdmin($this->user);

        Livewire::test(ApplicationGeneral::class, ['application' => $this->application])
            ->set('customDockerRunOptions', '--cap-add SYS_ADMIN')
            ->call('submit')
            ->assertNotDispatched('error');

        expect($this->application->refresh()->custom_docker_run_options)->toBe('--cap-add SYS_ADMIN');
    });

    it('lets other members save the form when an admin set the options earlier', function () {
        $this->application->update(['custom_docker_run_options' => '--privileged']);

        Livewire::test(ApplicationGeneral::class, ['application' => $this->application->fresh()])
            ->set('description', 'changed by a member')
            ->call('submit')
            ->assertNotDispatched('error');

        expect($this->application->refresh())
            ->description->toBe('changed by a member')
            ->custom_docker_run_options->toBe('--privileged');
    });

    it('refuses dangerous run options on a database for a team owner who is not a platform admin', function () {
        $database = create_standalone_postgresql($this->environment->id, $this->destination, databaseImage: 'postgres:16-alpine')->fresh();

        Livewire::test(PostgresqlGeneral::class, ['database' => $database])
            ->set('customDockerRunOptions', '--device /dev/sda')
            ->call('submit')
            ->assertDispatched('error', fn (string $event, array $params): bool => str_contains($params[0], '--device /dev/sda'));

        expect($database->refresh()->custom_docker_run_options)->toBeNull();
    });

    it('refuses a service compose file that mounts the docker socket', function () {
        Livewire::test(StackForm::class, ['service' => $this->service])
            ->call('saveCompose', HOST_ACCESS_DOCKER_SOCKET_COMPOSE)
            ->assertDispatched('error', fn (string $event, array $params): bool => str_contains($params[0], '/var/run/docker.sock'));

        expect($this->service->refresh()->docker_compose_raw)->toBe(HOST_ACCESS_SAFE_COMPOSE);
    });

    it('refuses to create a service from a compose file with host access', function () {
        $count = Service::count();
        $routeParams = ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid];

        Livewire::withQueryParams(['destination' => $this->destination->uuid, 'server_id' => $this->server->id])
            ->test(DockerCompose::class, $routeParams)
            ->set('parameters', $routeParams)
            ->set('query', ['destination' => $this->destination->uuid])
            ->set('dockerComposeRaw', "services:\n  web:\n    image: x\n    privileged: true\n")
            ->call('submit')
            ->assertDispatched('error', fn (string $event, array $params): bool => str_contains($params[0], 'privileged'));

        expect(Service::count())->toBe($count);
    });
});

describe('api', function () {
    beforeEach(function () {
        // API requests authenticate with the bearer token only, not with the session from the outer beforeEach.
        auth()->forgetGuards();
        $this->app['auth']->guard('web')->logout();
        $this->token = hostAccessApiToken($this->user, $this->team);
    });

    it('refuses dangerous run options when a non-admin updates an application', function () {
        $this->withToken($this->token)
            ->patchJson("/api/v1/applications/{$this->application->uuid}", ['custom_docker_run_options' => '--privileged'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('custom_docker_run_options');

        expect($this->application->refresh()->custom_docker_run_options)->toBeNull();
    });

    it('lets a platform admin set dangerous run options through the API', function () {
        makePlatformAdmin($this->user);

        $this->withToken($this->token)
            ->patchJson("/api/v1/applications/{$this->application->uuid}", ['custom_docker_run_options' => '--privileged'])
            ->assertOk();

        expect($this->application->refresh()->custom_docker_run_options)->toBe('--privileged');
    });

    it('refuses dangerous run options when a non-admin creates an application', function () {
        $count = Application::count();

        $this->withToken($this->token)->postJson('/api/v1/applications/dockerimage', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_registry_image_name' => 'nginx',
            'ports_exposes' => '80',
            'custom_docker_run_options' => '--pid=host',
        ])->assertUnprocessable()->assertJsonValidationErrors('custom_docker_run_options');

        expect(Application::count())->toBe($count);
    });

    it('returns 403 when a non-admin turns the HTTPS redirect off', function () {
        $this->application->settings()->update(['is_force_https_enabled' => true]);

        $this->withToken($this->token)
            ->patchJson("/api/v1/applications/{$this->application->uuid}", ['is_force_https_enabled' => false])
            ->assertForbidden();

        expect((bool) $this->application->settings->fresh()->is_force_https_enabled)->toBeTrue()
            ->and(AuditEvent::query()->where('event', 'api.application.force_https.disable')->where('result', 'denied')->exists())->toBeTrue();
    });

    it('returns 403 when a non-admin creates an application with the HTTPS redirect off', function () {
        $count = Application::count();

        $this->withToken($this->token)->postJson('/api/v1/applications/dockerimage', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_registry_image_name' => 'nginx',
            'ports_exposes' => '80',
            'is_force_https_enabled' => false,
        ])->assertForbidden();

        expect(Application::count())->toBe($count);
    });

    it('lets a platform admin turn the HTTPS redirect off', function () {
        makePlatformAdmin($this->user);
        $this->application->settings()->update(['is_force_https_enabled' => true]);

        $this->withToken($this->token)
            ->patchJson("/api/v1/applications/{$this->application->uuid}", ['is_force_https_enabled' => false])
            ->assertOk();

        expect((bool) $this->application->settings->fresh()->is_force_https_enabled)->toBeFalse();
    });

    it('returns 403 when a non-admin turns the HTTPS redirect off for a service application', function () {
        $serviceApplication = ServiceApplication::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'web',
            'service_id' => $this->service->id,
            'image' => 'nginx:alpine',
            'is_force_https_enabled' => true,
        ]);

        $this->withToken($this->token)
            ->patchJson("/api/v1/services/{$this->service->uuid}/applications/{$serviceApplication->uuid}", ['is_force_https_enabled' => false])
            ->assertForbidden();

        expect((bool) $serviceApplication->fresh()->is_force_https_enabled)->toBeTrue();
    });

    it('refuses a service compose file with host access from a non-admin', function () {
        $count = Service::count();

        $this->withToken($this->token)->postJson('/api/v1/services', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_compose_raw' => base64_encode(HOST_ACCESS_DOCKER_SOCKET_COMPOSE),
        ])->assertUnprocessable()->assertJsonValidationErrors('docker_compose_raw');

        expect(Service::count())->toBe($count);
    });

    it('refuses a one-click service that mounts the docker socket for a non-admin', function () {
        $count = Service::count();

        $this->withToken($this->token)->postJson('/api/v1/services', [
            'type' => 'portainer',
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
        ])->assertUnprocessable()->assertJsonValidationErrors('type');

        expect(Service::count())->toBe($count);
    });

    it('refuses a service compose update with host access from a non-admin', function () {
        $this->withToken($this->token)
            ->patchJson("/api/v1/services/{$this->service->uuid}", ['docker_compose_raw' => base64_encode(HOST_ACCESS_DOCKER_SOCKET_COMPOSE)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('docker_compose_raw');

        expect($this->service->refresh()->docker_compose_raw)->toBe(HOST_ACCESS_SAFE_COMPOSE);
    });
});

test('compose files that include or extend other files are admin-only because those files are not inspected', function (string $compose, string $finding) {
    expect(ContainerPrivilegePolicy::dangerousComposeSettings($compose))->toContain($finding);
})->with([
    'top-level include' => ["include:\n  - other.yml\nservices:\n  web:\n    image: nginx\n", 'include'],
    'extends from a file' => ["services:\n  web:\n    extends:\n      file: common.yml\n      service: base\n", 'web: extends.file'],
]);

test('extending a service in the same file is allowed', function () {
    $compose = "services:\n  base:\n    image: nginx\n  web:\n    extends:\n      service: base\n";

    expect(ContainerPrivilegePolicy::dangerousComposeSettings($compose))->toBe([]);
});
