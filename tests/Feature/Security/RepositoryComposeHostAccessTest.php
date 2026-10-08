<?php

use App\Exceptions\DeploymentException;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

const REPOSITORY_SAFE_COMPOSE = "services:\n  web:\n    image: nginx:alpine";
const REPOSITORY_HOST_ACCESS_COMPOSE = "services:\n  web:\n    image: nginx:alpine\n    privileged: true\n    volumes:\n      - '/:/host'";

beforeEach(function () {
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->team = Team::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member->id, ['role' => 'owner']);
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
        'docker_compose_raw' => REPOSITORY_SAFE_COMPOSE,
        'static_image' => 'nginx:alpine',
    ])->fresh();
});

function fakeRepositoryComposeContent(string $compose): void
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

test('a non-admin cannot load a repository Compose file that gives containers host access', function () {
    fakeRepositoryComposeContent(REPOSITORY_HOST_ACCESS_COMPOSE);
    $this->actingAs($this->member);

    expect(fn () => $this->application->loadComposeFile())
        ->toThrow(DeploymentException::class, 'Only platform administrators may give containers access to the host.');

    expect($this->application->refresh()->docker_compose_raw)->toBe(REPOSITORY_SAFE_COMPOSE)
        ->and(AuditEvent::query()->where('event', 'container.host_access.change')->where('result', 'denied')->exists())->toBeTrue();
});

test('a platform admin can load a repository Compose file that gives containers host access', function () {
    fakeRepositoryComposeContent(REPOSITORY_HOST_ACCESS_COMPOSE);
    $admin = User::factory()->create();
    $this->rootTeam->members()->attach($admin->id, ['role' => 'admin']);
    $this->actingAs($admin);

    $this->application->loadComposeFile();

    expect($this->application->refresh()->docker_compose_raw)->toBe(REPOSITORY_HOST_ACCESS_COMPOSE);
});

test('a non-admin can load a repository Compose file without host access', function () {
    $compose = "services:\n  web:\n    image: nginx:alpine\n    volumes:\n      - 'data:/data'\nvolumes:\n  data: {}";
    fakeRepositoryComposeContent($compose);
    $this->actingAs($this->member);

    $this->application->loadComposeFile();

    expect($this->application->refresh()->docker_compose_raw)->toBe($compose);
});

test('an unchanged repository Compose file with existing host access still loads for a non-admin', function () {
    $this->application->forceFill(['docker_compose_raw' => REPOSITORY_HOST_ACCESS_COMPOSE])->save();
    fakeRepositoryComposeContent(REPOSITORY_HOST_ACCESS_COMPOSE);
    $this->actingAs($this->member);

    $this->application->loadComposeFile();

    expect($this->application->refresh()->docker_compose_raw)->toBe(REPOSITORY_HOST_ACCESS_COMPOSE);
});
