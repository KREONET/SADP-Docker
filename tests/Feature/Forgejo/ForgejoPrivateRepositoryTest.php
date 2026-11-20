<?php

use App\Livewire\Project\New\ForgejoPrivateRepository;
use App\Models\Application;
use App\Models\Environment;
use App\Models\ForgejoApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);

    $this->team = Team::factory()->create(['name' => 'Tenant']);
    $this->user = User::factory()->create(['name' => 'Tenant Dev']);
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'dest-'.fake()->unique()->word(),
        'network' => 'net-'.fake()->unique()->word(),
    ]);
    $this->source = ForgejoApp::factory()->create(['name' => 'company-forgejo']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->routeParams = ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid];
});

function forgejoRepository(int $id, string $fullName, string $defaultBranch = 'main'): array
{
    return ['id' => $id, 'name' => explode('/', $fullName)[1], 'full_name' => $fullName, 'default_branch' => $defaultBranch, 'private' => true, 'html_url' => "https://93.184.216.34/{$fullName}"];
}

function fakeForgejoRepositories(array $repositories, array $branches = ['develop', 'main', 'feature/x']): void
{
    Http::fake([
        '93.184.216.34/api/v1/user/repos*' => Http::response($repositories, 200),
        '93.184.216.34/api/v1/repos/*/branches*' => Http::response(array_map(fn (string $name) => ['name' => $name], $branches), 200),
    ]);
}

function forgejoComponent($test)
{
    request()->headers->set('referer', route('project.resource.create', $test->routeParams).'?destination='.$test->destination->uuid);

    // On the real page these come from the route and the query string.
    return Livewire::withUrlParams(['destination' => $test->destination->uuid])
        ->test(ForgejoPrivateRepository::class, $test->routeParams)
        ->set('parameters', $test->routeParams)
        ->set('query', ['destination' => $test->destination->uuid]);
}

test('the registered forgejo servers are offered and their repositories are listed', function () {
    fakeForgejoRepositories([forgejoRepository(3, 'platform/web'), forgejoRepository(1, 'Platform/api'), forgejoRepository(2, 'docs/site')]);

    $component = forgejoComponent($this)
        ->assertSee('company-forgejo')
        ->call('loadRepositories', $this->source->id)
        ->assertSet('current_step', 'repository')
        ->assertSet('total_repositories_count', 3);

    expect(collect($component->get('repositories'))->pluck('full_name')->all())->toBe(['docs/site', 'Platform/api', 'platform/web']);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'token '.$this->source->access_token)
        && str_starts_with($request->url(), 'https://93.184.216.34/api/v1/user/repos'));
});

test('all pages of repositories are loaded', function () {
    $firstPage = array_map(fn (int $i) => forgejoRepository($i, "org/repo-{$i}"), range(1, 50));
    Http::fake([
        '93.184.216.34/api/v1/user/repos?page=1*' => Http::response($firstPage, 200),
        '93.184.216.34/api/v1/user/repos?page=2*' => Http::response([forgejoRepository(51, 'org/repo-51')], 200),
    ]);

    forgejoComponent($this)
        ->call('loadRepositories', $this->source->id)
        ->assertSet('total_repositories_count', 51);
});

test('selecting a repository loads its branches and preselects the default branch', function () {
    fakeForgejoRepositories([forgejoRepository(7, 'platform/api', 'develop')]);

    $component = forgejoComponent($this)
        ->call('loadRepositories', $this->source->id)
        ->set('selected_repository_id', 7)
        ->assertSet('selected_repository_path', 'platform/api')
        ->assertSet('selected_branch_name', 'develop');

    expect(collect($component->get('branches'))->pluck('name')->all())->toContain('main', 'develop', 'feature/x');
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://93.184.216.34/api/v1/repos/platform/api/branches'));
});

test('submitting creates an application that deploys from the forgejo source', function () {
    fakeForgejoRepositories([forgejoRepository(7, 'platform/api')]);

    forgejoComponent($this)
        ->call('loadRepositories', $this->source->id)
        ->set('selected_repository_id', 7)
        ->set('selected_branch_name', 'develop')
        ->set('build_pack', 'nixpacks')
        ->call('submit')
        ->assertHasNoErrors();

    $application = Application::query()->firstOrFail();
    expect($application)
        ->git_repository->toBe('platform/api')
        ->git_branch->toBe('develop')
        ->repository_project_id->toBe(7)
        ->source_type->toBe(ForgejoApp::class)
        ->source_id->toBe($this->source->id)
        ->environment_id->toBe($this->environment->id)
        ->destination_id->toBe($this->destination->id)
        ->and($application->fqdn)->not->toBeNull();
});

test('a repository the token cannot read is rejected', function () {
    fakeForgejoRepositories([forgejoRepository(7, 'platform/api')]);

    forgejoComponent($this)
        ->call('loadRepositories', $this->source->id)
        ->set('selected_repository_path', 'someone-else/secret')
        ->set('selected_branch_name', 'main')
        ->call('submit')
        ->assertDispatched('error');

    expect(Application::query()->count())->toBe(0);
});

test('repository paths with shell or url characters are rejected', function (string $path) {
    fakeForgejoRepositories([forgejoRepository(7, 'platform/api')]);

    forgejoComponent($this)
        ->call('loadRepositories', $this->source->id)
        ->set('selected_repository_path', $path)
        ->call('submit')
        ->assertDispatched('error');

    expect(Application::query()->count())->toBe(0);
})->with(["platform/api'; id; '", 'platform/api/extra', '../etc/passwd', 'platform api']);

test('a failing forgejo server shows an error and no repositories', function (int $status) {
    Http::fake(['93.184.216.34/api/v1/user/repos*' => Http::response(['message' => 'nope'], $status)]);

    forgejoComponent($this)
        ->call('loadRepositories', $this->source->id)
        ->assertDispatched('error')
        ->assertSet('current_step', 'forgejo_apps');
})->with([401, 403, 404, 500]);

test('a source owned by another team cannot be used', function () {
    $other = Team::factory()->create();
    $private = ForgejoApp::factory()->create(['name' => 'other-team-forgejo', 'is_system_wide' => false, 'team_id' => $other->id]);
    Http::fake();

    forgejoComponent($this)
        ->assertDontSee('other-team-forgejo')
        ->call('loadRepositories', $private->id)
        ->assertDispatched('error');

    Http::assertNothingSent();
});

test('without a registered server the page tells how to get one', function () {
    ForgejoApp::query()->delete();

    forgejoComponent($this)->assertSee('Ask a platform admin to add a Forgejo server.');
});

test('platform admins are pointed to the sources page when no server exists', function () {
    ForgejoApp::query()->delete();
    $admin = User::factory()->create(['name' => 'Platform Admin']);
    Team::find(0)->members()->attach($admin->id, ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => Team::find(0)]);

    forgejoComponent($this)->assertSee('Open Platform Admin → Sources');
});

test('members of every team see the instance-wide server', function () {
    $otherTeam = Team::factory()->create();
    $member = User::factory()->create(['name' => 'Other Member']);
    $otherTeam->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $otherTeam]);

    forgejoComponent($this)->assertSee('company-forgejo');
});
