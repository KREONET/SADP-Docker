<?php

use App\Actions\User\DeleteUserAccount;
use App\Jobs\DeleteResourceJob;
use App\Livewire\Team\AdminView;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    Cache::flush();
    $this->rootTeam = Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->root = User::factory()->create(['id' => 0, 'name' => 'Root User']);
    $this->rootTeam->members()->syncWithoutDetaching([$this->root->id => ['role' => 'owner']]);

    $this->platformAdmin = User::factory()->create(['name' => 'Platform Admin']);
    $this->rootTeam->members()->attach($this->platformAdmin->id, ['role' => 'admin']);
});

/**
 * An application on a server owned by the given team.
 */
function applicationOwnedByTeam(Team $team): Application
{
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
}

function signInAsPlatformAdmin(User $admin, Team $rootTeam): void
{
    test()->actingAs($admin);
    session(['currentTeam' => $rootTeam]);
}

it('stops and removes the applications of teams the user is alone in, but not of shared teams', function () {
    Bus::fake([DeleteResourceJob::class]);
    $user = User::factory()->create();
    $ownApplication = applicationOwnedByTeam($user->teams()->first());

    $sharedTeam = Team::factory()->create();
    $sharedTeam->members()->attach($user->id, ['role' => 'member']);
    $sharedTeam->members()->attach(User::factory()->create()->id, ['role' => 'owner']);
    $sharedApplication = applicationOwnedByTeam($sharedTeam);

    DeleteUserAccount::execute($user, 'admin');

    Bus::assertDispatchedSync(DeleteResourceJob::class, fn (DeleteResourceJob $job) => $job->resource->is($ownApplication));
    Bus::assertNotDispatchedSync(DeleteResourceJob::class, fn (DeleteResourceJob $job) => $job->resource->is($sharedApplication));
    expect(User::find($user->id))->toBeNull()
        ->and(Application::find($sharedApplication->id))->not->toBeNull()
        ->and(Team::find($sharedTeam->id))->not->toBeNull();
});

it('removes the application through the regular resource deletion job', function () {
    Process::fake();
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $application = applicationOwnedByTeam($team);
    $application->environment_variables()->create(['key' => 'SECRET', 'value' => 'value']);

    DeleteUserAccount::execute($user, 'self');

    expect(Application::withTrashed()->find($application->id))->toBeNull()
        ->and(DB::table('environment_variables')->where('resourceable_id', $application->id)->exists())->toBeFalse()
        ->and(Team::find($team->id))->toBeNull();
});

it('still deletes the account when the remote cleanup of a resource fails', function () {
    Process::fake(fn () => Process::result(errorOutput: 'ssh: connect to host: Connection refused', exitCode: 255));
    $user = User::factory()->create();
    $application = applicationOwnedByTeam($user->teams()->first());

    DeleteUserAccount::execute($user, 'admin');

    expect(User::find($user->id))->toBeNull()
        ->and(Application::withTrashed()->find($application->id))->toBeNull();
});

it('deletes the avatar file of the account', function () {
    Bus::fake([DeleteResourceJob::class]);
    Storage::fake('images');
    $user = User::factory()->create();
    Storage::disk('images')->put('avatars/leaving.jpg', 'image');
    $user->forceFill(['avatar_path' => 'avatars/leaving.jpg', 'avatar_storage_type' => 'local'])->save();

    DeleteUserAccount::execute($user, 'self');

    Storage::disk('images')->assertMissing('avatars/leaving.jpg');
});

it('lets platform admins delete a user with the same cleanup as a withdrawal', function () {
    Bus::fake([DeleteResourceJob::class]);
    $user = User::factory()->create();
    $application = applicationOwnedByTeam($user->teams()->first());
    DB::table('sessions')->insert(['id' => 'user-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    signInAsPlatformAdmin($this->platformAdmin, $this->rootTeam);

    Livewire::test(AdminView::class)
        ->call('delete', $user->id, 'password')
        ->assertReturned(true);

    Bus::assertDispatchedSync(DeleteResourceJob::class, fn (DeleteResourceJob $job) => $job->resource->is($application));
    expect(User::find($user->id))->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('does not let platform admins delete their own account from the user list', function () {
    Bus::fake([DeleteResourceJob::class]);
    signInAsPlatformAdmin($this->platformAdmin, $this->rootTeam);

    Livewire::test(AdminView::class)
        ->call('delete', $this->platformAdmin->id, 'password')
        ->assertDispatched('error');

    expect(User::find($this->platformAdmin->id))->not->toBeNull();
});

it('does not let platform admins delete the root account', function () {
    Bus::fake([DeleteResourceJob::class]);
    signInAsPlatformAdmin($this->platformAdmin, $this->rootTeam);

    Livewire::test(AdminView::class)
        ->call('delete', 0, 'password')
        ->assertDispatched('error');

    expect(User::find(0))->not->toBeNull();
});
