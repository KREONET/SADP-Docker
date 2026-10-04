<?php

use App\Actions\User\DeleteUserAccount;
use App\Livewire\Profile\Index as ProfileIndex;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    $this->rootTeam = Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
});

/**
 * A user whose personal team owns a server with an application on it.
 *
 * @return array{0: User, 1: Team, 2: Server, 3: Application}
 */
function userWithPersonalResources(): array
{
    $user = User::factory()->create(['name' => 'Leaving User']);
    $team = $user->teams()->first();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    return [$user, $team, $server, $application];
}

it('deletes the account, its personal team, servers, and applications, then signs out', function () {
    [$user, $team, $server, $application] = userWithPersonalResources();
    $this->actingAs($user);
    session(['currentTeam' => $team]);
    DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);

    Livewire::test(ProfileIndex::class)
        ->assertSee('Delete account')
        ->call('deleteAccount', 'password')
        ->assertRedirect(route('login'));

    expect(User::find($user->id))->toBeNull()
        ->and(Team::find($team->id))->toBeNull()
        ->and(Server::withTrashed()->find($server->id))->toBeNull()
        ->and(Application::withTrashed()->find($application->id))->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(auth()->check())->toBeFalse();
});

it('keeps the account when the password is wrong', function () {
    [$user, $team] = userWithPersonalResources();
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(ProfileIndex::class)
        ->call('deleteAccount', 'wrong-password')
        ->assertReturned('The provided password is incorrect.')
        ->assertNoRedirect();

    expect(User::find($user->id))->not->toBeNull();
});

it('lets sso users withdraw without a password', function () {
    [$user, $team] = userWithPersonalResources();
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'oidc',
        'issuer' => 'https://idp.example.com',
        'provider_user_id' => 'idp-user-1',
        'email' => $user->email,
    ]);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(ProfileIndex::class)
        ->call('deleteAccount', '')
        ->assertRedirect(route('login'));

    expect(User::find($user->id))->toBeNull()
        ->and(OauthIdentity::where('user_id', $user->id)->exists())->toBeFalse();
});

it('moves ownership of a shared team to another member instead of deleting it', function () {
    [$user] = userWithPersonalResources();
    $sharedTeam = Team::factory()->create();
    $sharedTeam->members()->attach($user->id, ['role' => 'owner']);
    $colleague = User::factory()->create();
    $sharedTeam->members()->attach($colleague->id, ['role' => 'member']);

    DeleteUserAccount::execute($user, 'self');

    expect(Team::find($sharedTeam->id))->not->toBeNull()
        ->and($colleague->fresh()->roleInTeam($sharedTeam->id))->toBe('owner');
});

it('does not let the root user withdraw', function () {
    $root = User::factory()->create(['id' => 0, 'name' => 'Root User']);
    $this->rootTeam->members()->syncWithoutDetaching([$root->id => ['role' => 'owner']]);
    $this->actingAs($root);
    session(['currentTeam' => $this->rootTeam]);

    Livewire::test(ProfileIndex::class)
        ->assertDontSee('Delete account')
        ->call('deleteAccount', 'password')
        ->assertDispatched('error');

    expect(User::find(0))->not->toBeNull()
        ->and(fn () => DeleteUserAccount::execute($root, 'self'))->toThrow(RuntimeException::class);
});
