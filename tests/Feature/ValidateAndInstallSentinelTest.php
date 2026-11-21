<?php

use App\Enums\ProxyTypes;
use App\Enums\ServerRole;
use App\Events\ServerReachabilityChanged;
use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\ValidateAndInstallServerJob;
use App\Livewire\Server\ValidateAndInstall;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://sadp.example.com']);
    Event::fake([ServerReachabilityChanged::class]);

    $user = User::factory()->create();
    $this->team = Team::factory()->create();
    $user->teams()->attach($this->team, ['role' => 'owner']);
    makePlatformAdmin($user);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'user' => 'root',
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->proxy->set('type', ProxyTypes::NONE->value);
    $this->server->save();
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true]);
});

function validatedComponent(Server $server)
{
    fakeRemoteProcesses([
        '*compose*' => Process::result(output: 'v2.40.3'),
        '*docker version*' => Process::result(output: '{"Server":{"Version":"29.6.2"}}'),
        '*name=^coolify-sentinel$*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);
    $component = Livewire::test(ValidateAndInstall::class, ['server' => $server])->instance();
    $component->uptime = '1 day';
    $component->validateDockerVersion();

    return $component;
}

it('starts the Sentinel installation after the web validation', function () {
    Queue::fake();

    $component = validatedComponent($this->server->fresh());

    expect($component->error)->toBeNull()
        ->and($component->sentinel_installed)->toBeFalse()
        ->and($component->sentinel_error)->toBeNull()
        ->and($this->server->fresh()->settings->sentinel_custom_url)->toBe('https://sadp.example.com');
    Queue::assertPushed(CheckAndStartSentinelJob::class);
});

it('marks Sentinel as installed when its container runs', function () {
    Queue::fake();
    $component = validatedComponent($this->server->fresh());

    fakeRemoteProcesses([
        '*name=^coolify-sentinel$*' => Process::result(output: 'running'),
        '*' => Process::result(),
    ]);
    $component->checkSentinelInstallation();

    expect($component->sentinel_installed)->toBeTrue()
        ->and($component->sentinel_error)->toBeNull();
});

it('reports a Sentinel installation that does not start in time', function () {
    Queue::fake();
    $component = validatedComponent($this->server->fresh());
    $component->sentinel_status_checks = ValidateAndInstall::SENTINEL_MAX_STATUS_CHECKS - 1;

    $component->checkSentinelInstallation();

    expect($component->sentinel_installed)->toBeFalse()
        ->and($component->sentinel_error)->toContain('Sentinel did not start in time');
});

it('does not install Sentinel on a build server', function () {
    Queue::fake();
    $this->server->settings->update(['server_role' => ServerRole::BUILD]);

    $component = validatedComponent($this->server->fresh());

    expect($component->sentinel_installed)->toBeNull();
    Queue::assertNotPushed(CheckAndStartSentinelJob::class);
});

it('installs Sentinel in the background validation job', function () {
    Queue::fake();
    fakeRemoteProcesses([
        '*name=^coolify-sentinel$*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    $server = Mockery::mock($this->server->fresh())->makePartial();
    $server->shouldReceive('validateConnection')->andReturn(['uptime' => true, 'error' => null]);
    $server->shouldReceive('validateOS')->andReturn('debian');
    $server->shouldReceive('validatePrerequisites')->andReturn(['success' => true]);
    $server->shouldReceive('validateDockerEngine')->andReturn(true);
    $server->shouldReceive('validateDockerCompose')->andReturn(true);
    $server->shouldReceive('validateDockerEngineVersion')->andReturn(true);
    $server->shouldReceive('gatherServerMetadata')->andReturnNull();

    (new ValidateAndInstallServerJob($server))->handle();

    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), 'coollabsio/sentinel'));
    expect((bool) $this->server->fresh()->settings->is_sentinel_enabled)->toBeTrue();
});
