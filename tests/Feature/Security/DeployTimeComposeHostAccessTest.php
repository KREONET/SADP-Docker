<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Notifications\SecurityAlert;
use App\Support\ContainerPrivilegePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const DEPLOY_SAFE_COMPOSE = "services:\n  web:\n    image: nginx:alpine";
const DEPLOY_HOST_ACCESS_COMPOSE = "services:\n  web:\n    image: nginx:alpine\n    privileged: true\n    volumes:\n      - '/var/run/docker.sock:/var/run/docker.sock'";

beforeEach(function () {
    $this->withoutDefer();
    Queue::fake();
    Notification::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->rootTeam->discordNotificationSettings->update([
        'discord_enabled' => true,
        'discord_webhook_url' => 'https://discord.com/api/webhooks/1/abc',
    ]);
    $this->team = Team::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member->id, ['role' => 'owner']);
    $this->admin = User::factory()->create();
    $this->rootTeam->members()->attach($this->admin->id, ['role' => 'admin']);
    $this->team->members()->attach($this->admin->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'git_repository' => 'https://github.com/coollabsio/compose-app',
        'git_branch' => 'main',
        'base_directory' => '/',
        'docker_compose_location' => '/docker-compose.yml',
        'docker_compose_raw' => DEPLOY_SAFE_COMPOSE,
        'static_image' => 'nginx:alpine',
    ])->fresh();
});

function fakeDeployRepositoryCompose(string $compose): void
{
    Process::fake(function ($process) use ($compose) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        if (str_contains($command, 'git --version')) {
            return Process::result(output: 'git version 2.43.0');
        }
        if (str_contains($command, 'head -c')) {
            return Process::result(output: $compose);
        }

        return Process::result(output: '');
    });
}

/**
 * Queues a deployment the way the UI, API or a webhook does, and returns its queue row.
 */
function queueHostAccessDeployment(Application $application, bool $isWebhook = false, bool $isApi = false): ApplicationDeploymentQueue
{
    $deploymentUuid = new_public_id();
    $result = queue_application_deployment(
        application: $application,
        deployment_uuid: $deploymentUuid,
        is_webhook: $isWebhook,
        is_api: $isApi,
    );
    expect($result['status'])->toBe('queued');

    return ApplicationDeploymentQueue::query()->where('deployment_uuid', $deploymentUuid)->firstOrFail();
}

/**
 * Runs only the Compose loading step of a deployment job for $deployment.
 */
function runDeployComposeLoad(Application $application, ApplicationDeploymentQueue $deployment): void
{
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    foreach ([
        'application' => $application,
        'application_deployment_queue' => $deployment,
        'pull_request_id' => 0,
        'deployment_uuid' => $deployment->deployment_uuid,
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }

    (new ReflectionMethod(ApplicationDeploymentJob::class, 'loadComposeFileForDeployment'))->invoke($job);
}

/**
 * @return list<string>
 */
function visibleDeploymentLog(ApplicationDeploymentQueue $deployment): array
{
    return collect(json_decode((string) $deployment->refresh()->logs, true) ?? [])
        ->reject(fn (array $entry): bool => (bool) $entry['hidden'])
        ->pluck('output')
        ->all();
}

test('a webhook deployment that adds host access from the repository fails without saving the Compose file', function () {
    $deployment = queueHostAccessDeployment($this->application, isWebhook: true);
    expect($deployment->triggered_by_user_id)->toBeNull();
    fakeDeployRepositoryCompose(DEPLOY_HOST_ACCESS_COMPOSE);

    expect(fn () => runDeployComposeLoad($this->application, $deployment))
        ->toThrow(DeploymentException::class, 'Deployment stopped: the Docker Compose file in the repository adds settings that give containers access to the host');

    $log = implode("\n", visibleDeploymentLog($deployment));
    expect($log)->toContain('Only platform administrators may give containers access to the host. Remove: web: privileged, web: volume /var/run/docker.sock')
        ->and($this->application->refresh()->docker_compose_raw)->toBe(DEPLOY_SAFE_COMPOSE);

    $audit = AuditEvent::query()->where('event', 'container.host_access.change')->where('result', 'denied')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->team_id)->toBe($this->team->id);

    Notification::assertSentTo($this->rootTeam, SecurityAlert::class, fn (SecurityAlert $alert): bool => $alert->type === 'container_host_access_blocked');
});

test('a deployment whose repository Compose file has the same host access as the stored one still deploys', function () {
    $this->application->forceFill(['docker_compose_raw' => DEPLOY_HOST_ACCESS_COMPOSE])->save();
    $deployment = queueHostAccessDeployment($this->application, isWebhook: true);
    $reformatted = "# reformatted\nservices:\n  web:\n    privileged: true\n    image: 'nginx:alpine'\n    volumes:\n      -   \"/var/run/docker.sock:/var/run/docker.sock\"\n";
    fakeDeployRepositoryCompose($reformatted);

    runDeployComposeLoad($this->application, $deployment);

    expect($this->application->refresh()->docker_compose_raw)->toBe(trim($reformatted))
        ->and(AuditEvent::query()->where('event', 'container.host_access.change')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('a deployment cannot add host access beyond the stored Compose file', function () {
    $this->application->forceFill(['docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n    privileged: true"])->save();
    $deployment = queueHostAccessDeployment($this->application, isWebhook: true);
    fakeDeployRepositoryCompose(DEPLOY_HOST_ACCESS_COMPOSE);

    expect(fn () => runDeployComposeLoad($this->application, $deployment))->toThrow(DeploymentException::class);

    expect(implode("\n", visibleDeploymentLog($deployment)))->toContain('Remove: web: volume /var/run/docker.sock')
        ->not->toContain('web: privileged');
});

test('a deployment started by a platform admin may add host access', function () {
    $this->actingAs($this->admin);
    $deployment = queueHostAccessDeployment($this->application);
    auth()->logout();
    expect($deployment->triggered_by_user_id)->toBe($this->admin->id);
    fakeDeployRepositoryCompose(DEPLOY_HOST_ACCESS_COMPOSE);

    runDeployComposeLoad($this->application, $deployment);

    expect($this->application->refresh()->docker_compose_raw)->toBe(DEPLOY_HOST_ACCESS_COMPOSE);
    Notification::assertNothingSent();
});

test('a deployment started by a team member who is not a platform admin may not add host access', function () {
    $this->actingAs($this->member);
    $deployment = queueHostAccessDeployment($this->application);
    auth()->logout();
    expect($deployment->triggered_by_user_id)->toBe($this->member->id);
    fakeDeployRepositoryCompose(DEPLOY_HOST_ACCESS_COMPOSE);

    expect(fn () => runDeployComposeLoad($this->application, $deployment))->toThrow(DeploymentException::class);

    expect($this->application->refresh()->docker_compose_raw)->toBe(DEPLOY_SAFE_COMPOSE)
        ->and(AuditEvent::query()->where('event', 'container.host_access.change')->where('result', 'denied')->exists())->toBeTrue();
});

test('a deployment with a safe repository Compose file deploys', function () {
    $deployment = queueHostAccessDeployment($this->application, isWebhook: true);
    $compose = "services:\n  web:\n    image: nginx:1.27\n    volumes:\n      - 'data:/data'\n      - './config:/config'\nvolumes:\n  data: {}";
    fakeDeployRepositoryCompose($compose);

    runDeployComposeLoad($this->application, $deployment);

    expect($this->application->refresh()->docker_compose_raw)->toBe($compose);
    Notification::assertNothingSent();
});

test('a webhook deployment never records a user, even in an authenticated request', function () {
    $this->actingAs($this->admin);

    expect(queueHostAccessDeployment($this->application, isWebhook: true)->triggered_by_user_id)->toBeNull();
});

test('an API deployment records the token user', function () {
    $plainTextToken = Str::random(40);
    $token = $this->member->tokens()->create([
        'name' => 'deploy-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken])
        ->postJson('/api/v1/deploy', ['uuid' => $this->application->uuid])
        ->assertSuccessful();

    $deployment = $this->application->deployment_queue()->latest('id')->firstOrFail();
    expect($deployment->triggered_by_user_id)->toBe($this->member->id)
        ->and($deployment->is_api)->toBeTruthy();
});

test('newComposeFindings ignores findings the baseline already has', function () {
    expect(ContainerPrivilegePolicy::newComposeFindings(DEPLOY_HOST_ACCESS_COMPOSE, DEPLOY_HOST_ACCESS_COMPOSE))->toBe([])
        ->and(ContainerPrivilegePolicy::newComposeFindings(DEPLOY_SAFE_COMPOSE, DEPLOY_HOST_ACCESS_COMPOSE))
        ->toBe(['web: privileged', 'web: volume /var/run/docker.sock'])
        ->and(ContainerPrivilegePolicy::newComposeFindings(null, DEPLOY_SAFE_COMPOSE))->toBe([]);
});
