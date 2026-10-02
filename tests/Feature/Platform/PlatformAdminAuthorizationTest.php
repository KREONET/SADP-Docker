<?php

use App\Jobs\DeleteResourceJob;
use App\Livewire\Platform\Applications;
use App\Livewire\Platform\Dashboard;
use App\Livewire\Platform\Groups;
use App\Livewire\Platform\Logs;
use App\Livewire\Platform\Security;
use App\Livewire\Platform\Sources;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0, 'disable_two_step_confirmation' => true]);

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->platformAdmin = User::factory()->create();
    $this->rootTeam->members()->attach($this->platformAdmin->id, ['role' => 'admin']);

    $this->otherTeam = Team::factory()->create(['name' => 'Tenant Team']);
    $this->tenantAdmin = User::factory()->create();
    $this->otherTeam->members()->attach($this->tenantAdmin->id, ['role' => 'owner']);

    $project = Project::factory()->create(['team_id' => $this->otherTeam->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $this->otherTeam->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $this->application = Application::create([
        'name' => 'tenant-app',
        'git_repository' => 'https://git.example.com/tenant/app.git',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->project = $project;
});

function actAsPlatformUser(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

test('guests are redirected to login from platform pages', function (string $path) {
    $this->get($path)->assertRedirect('/login');
})->with(['/platform', '/platform/applications', '/platform/groups', '/platform/sources', '/platform/security', '/platform/logs']);

test('team owners outside the root team cannot open platform pages', function (string $path) {
    actAsPlatformUser($this->tenantAdmin, $this->otherTeam);

    $this->get($path)->assertForbidden();
})->with(['/platform', '/platform/applications', '/platform/groups', '/platform/sources', '/platform/security', '/platform/logs']);

test('root team members without admin role cannot open platform components', function (string $component) {
    $member = User::factory()->create();
    $this->rootTeam->members()->attach($member->id, ['role' => 'member']);
    actAsPlatformUser($member, $this->rootTeam);

    Livewire::test($component)->assertForbidden();
})->with([Dashboard::class, Applications::class, Groups::class, Sources::class, Security::class, Logs::class]);

test('platform admins see applications of every team', function () {
    actAsPlatformUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Applications::class)
        ->assertOk()
        ->assertSee('tenant-app')
        ->assertSee('Tenant Team');
    Livewire::test(Dashboard::class)->assertOk()->assertSee('Applications');
});

test('platform admins can edit an application owned by another team', function () {
    actAsPlatformUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Applications::class)
        ->call('edit', $this->application->uuid)
        ->set('name', 'renamed-app')
        ->set('gitBranch', 'release')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->application->fresh())
        ->name->toBe('renamed-app')
        ->git_branch->toBe('release');
});

test('the allow-list still applies to platform admin edits', function () {
    InstanceSettings::get()->update(['is_source_allowlist_enabled' => true, 'allowed_git_sources' => 'git.example.com']);
    actAsPlatformUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Applications::class)
        ->call('edit', $this->application->uuid)
        ->set('gitRepository', 'https://github.com/evil/repo.git')
        ->call('save')
        ->assertHasErrors('gitRepository');

    expect($this->application->fresh()->git_repository)->toBe('https://git.example.com/tenant/app.git');
});

test('platform admins can delete an application owned by another team', function () {
    Queue::fake();
    actAsPlatformUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Applications::class)->call('delete', $this->application->uuid, '');

    Queue::assertPushed(DeleteResourceJob::class, fn (DeleteResourceJob $job) => $job->resource->is($this->application));
});

test('non platform admins are rejected before any application action can run', function () {
    Queue::fake();
    actAsPlatformUser($this->tenantAdmin, $this->otherTeam);

    Livewire::test(Applications::class)->assertForbidden();

    Queue::assertNothingPushed();
    expect(Application::query()->whereKey($this->application->id)->exists())->toBeTrue();
});

test('platform admins can rename teams and projects', function () {
    actAsPlatformUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Groups::class)
        ->call('editTeam', $this->otherTeam->id)
        ->set('name', 'Renamed Team')
        ->call('save')
        ->assertHasNoErrors()
        ->call('editProject', $this->project->id)
        ->set('name', 'Renamed Project')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->otherTeam->fresh()->name)->toBe('Renamed Team')
        ->and($this->project->fresh()->name)->toBe('Renamed Project');
});

test('teams with resources and the root team cannot be deleted', function () {
    actAsPlatformUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Groups::class)->call('deleteTeam', $this->otherTeam->id, '')->assertReturned(fn ($result) => is_string($result));
    Livewire::test(Groups::class)->call('deleteTeam', 0, '')->assertReturned('The root team cannot be deleted.');

    expect(Team::find($this->otherTeam->id))->not->toBeNull()
        ->and(Team::find(0))->not->toBeNull();
});

test('an empty team can be deleted and its members detached', function () {
    $emptyTeam = Team::factory()->create();
    $member = User::factory()->create();
    $emptyTeam->members()->attach($member->id, ['role' => 'owner']);
    actAsPlatformUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Groups::class)->call('deleteTeam', $emptyTeam->id, '')->assertReturned(true);

    expect(Team::find($emptyTeam->id))->toBeNull()
        ->and($member->teams()->where('team_id', $emptyTeam->id)->exists())->toBeFalse();
});

test('the last owner of a team cannot be demoted', function () {
    actAsPlatformUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Groups::class)->call('updateMemberRole', $this->otherTeam->id, $this->tenantAdmin->id, 'member');

    expect($this->otherTeam->members()->where('users.id', $this->tenantAdmin->id)->first()->pivot->role)->toBe('owner');
});

test('platform admins manage the allow-list, forgejo and trivy settings', function () {
    actAsPlatformUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Sources::class)
        ->set('isSourceAllowlistEnabled', true)
        ->set('allowedGitSources', "git.example.com\n git.example.com \n")
        ->call('saveAllowlist')
        ->set('forgejoBaseUrl', 'https://git.example.com/')
        ->set('forgejoBotToken', 'bot-token')
        ->call('saveForgejo')
        ->assertHasNoErrors()
        ->call('generateWebhookSecret');

    Livewire::test(Security::class)
        ->set('isTrivyScanEnabled', true)
        ->set('trivySeverities', ['CRITICAL'])
        ->call('saveTrivy')
        ->assertHasNoErrors();

    $settings = InstanceSettings::query()->find(0);
    expect($settings->is_source_allowlist_enabled)->toBeTrue()
        ->and($settings->allowed_git_sources)->toBe('git.example.com')
        ->and($settings->forgejo_base_url)->toBe('https://git.example.com')
        ->and($settings->forgejo_bot_token)->toBe('bot-token')
        ->and($settings->forgejo_webhook_secret)->toHaveLength(48)
        ->and($settings->is_trivy_scan_enabled)->toBeTrue()
        ->and($settings->trivy_severities)->toBe('CRITICAL');
});

test('an invalid trivy image is rejected', function () {
    actAsPlatformUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Security::class)
        ->set('trivyImage', 'aquasec/trivy; rm -rf /')
        ->call('saveTrivy')
        ->assertHasErrors('trivyImage');
});

test('sso only login cannot be enabled without an enabled provider', function () {
    actAsPlatformUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Security::class)->set('isSsoOnlyLoginEnabled', true)->call('saveLogin');

    expect(InstanceSettings::query()->find(0)->is_sso_only_login_enabled)->toBeFalse();
});
