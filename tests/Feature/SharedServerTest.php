<?php

use App\Actions\Docker\GetContainersStatus;
use App\Enums\ApplicationDeploymentStatus;
use App\Events\ServiceChecked;
use App\Livewire\Server\Show;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function sharedTestServer(Team $team, bool $shared = false): Server
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->forceFill(['is_shared' => $shared])->save();
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'server_role' => 'both',
        'is_swarm_worker' => false,
        'force_disabled' => false,
    ]);

    return $server->fresh();
}

function sharedTestDestination(Server $server): StandaloneDocker
{
    return StandaloneDocker::factory()->create([
        'server_id' => $server->id,
        'name' => 'dest-'.fake()->unique()->word(),
        'network' => 'net-'.fake()->unique()->word(),
    ]);
}

function sharedTestApplication(Team $team, StandaloneDocker $destination): Application
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
}

function sharedTestDeployment(Application $application, Server $server, string $uuid): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => $uuid,
        'server_id' => $server->id,
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);
}

beforeEach(function () {
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->teams()->attach($this->rootTeam, ['role' => 'owner']);

    $this->sharedServer = sharedTestServer($this->rootTeam, shared: true);
    $this->sharedDestination = sharedTestDestination($this->sharedServer);
    $this->privateServer = sharedTestServer($this->rootTeam);
    $this->privateDestination = sharedTestDestination($this->privateServer);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('offers shared servers but not private servers of other teams for new resources', function () {
    $ownServer = sharedTestServer($this->team);

    $usable = Server::isUsable()->pluck('id');

    expect($usable)->toContain($this->sharedServer->id)
        ->toContain($ownServer->id)
        ->not->toContain($this->privateServer->id);
});

it('keeps shared servers out of the team\'s own server management lists', function () {
    expect(Server::isUsable(includeShared: false)->pluck('id'))->not->toContain($this->sharedServer->id)
        ->and(Server::ownedByCurrentTeam()->pluck('id'))->not->toContain($this->sharedServer->id);
});

it('resolves destinations on shared servers only', function () {
    expect(find_resource_destination_for_current_team($this->sharedDestination->uuid)?->id)->toBe($this->sharedDestination->id)
        ->and(find_resource_destination_for_current_team($this->privateDestination->uuid))->toBeNull()
        // Destination management pages still only see the team's own destinations.
        ->and(find_destination_for_current_team($this->sharedDestination->uuid))->toBeNull();
});

it('stops offering a server once it is no longer shared', function () {
    $this->sharedServer->forceFill(['is_shared' => false])->save();

    expect(Server::isUsable()->pluck('id'))->not->toContain($this->sharedServer->id)
        ->and(find_resource_destination_for_current_team($this->sharedDestination->uuid))->toBeNull();
});

it('includes shared build servers for the team', function () {
    $buildServer = sharedTestServer($this->rootTeam, shared: true);
    $buildServer->settings()->update(['server_role' => 'build', 'is_build_server' => true]);

    expect(Server::buildServers($this->team->id)->pluck('id'))->toContain($buildServer->id);
});

it('shows a team only its own deployments on a shared server', function () {
    $ownApplication = sharedTestApplication($this->team, $this->sharedDestination);
    $otherTeam = Team::factory()->create();
    $otherApplication = sharedTestApplication($otherTeam, $this->sharedDestination);

    $own = sharedTestDeployment($ownApplication, $this->sharedServer, 'own-deployment');
    $other = sharedTestDeployment($otherApplication, $this->sharedServer, 'other-deployment');

    $visible = ApplicationDeploymentQueue::visibleToTeam($this->team->id)->pluck('id');
    expect($visible)->toContain($own->id)->not->toContain($other->id);

    // The owner team of the shared server still sees every deployment on it.
    $visibleToOwner = ApplicationDeploymentQueue::visibleToTeam($this->rootTeam->id)->pluck('id');
    expect($visibleToOwner)->toContain($own->id)->toContain($other->id);
});

it('lets a team read logs only on its own or shared servers', function () {
    expect($this->sharedServer->isAccessibleByTeam($this->team->id))->toBeTrue()
        ->and($this->privateServer->isAccessibleByTeam($this->team->id))->toBeFalse()
        ->and($this->privateServer->isAccessibleByTeam($this->rootTeam->id))->toBeTrue()
        ->and($this->sharedServer->isAccessibleByTeam(null))->toBeFalse();
});

it('lets a platform administrator share a server', function () {
    $this->actingAs($this->platformAdmin);
    session(['currentTeam' => $this->rootTeam]);
    Cache::flush();

    Livewire::test(Show::class, ['server_uuid' => $this->privateServer->uuid])
        ->set('isShared', true)
        ->call('saveSharing');

    expect($this->privateServer->fresh()->is_shared)->toBeTrue();
});

it('does not let a team owner who is not a platform administrator share a server', function () {
    $ownServer = sharedTestServer($this->team);

    Livewire::test(Show::class, ['server_uuid' => $ownServer->uuid])
        ->set('isShared', true)
        ->call('saveSharing');

    expect($ownServer->fresh()->is_shared)->toBeFalse();
});

it('refreshes resource statuses for the teams that deploy to a shared server', function () {
    sharedTestApplication($this->team, $this->sharedDestination);
    Event::fake([ServiceChecked::class]);

    GetContainersStatus::run($this->sharedServer, collect(), collect());

    Event::assertDispatched(ServiceChecked::class, fn (ServiceChecked $event) => $event->teamId === $this->team->id);
    Event::assertDispatched(ServiceChecked::class, fn (ServiceChecked $event) => $event->teamId === $this->rootTeam->id);
});
