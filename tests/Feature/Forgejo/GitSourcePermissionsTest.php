<?php

use App\Livewire\Source\Github\Create as GithubCreate;
use App\Livewire\Source\Gitlab\Create as GitlabCreate;
use App\Models\ForgejoApp;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\Support\TwoFactor;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->platformAdmin = User::factory()->create(['name' => 'Platform Admin']);
    $this->rootTeam->members()->attach($this->platformAdmin->id, ['role' => 'admin']);
    TwoFactor::confirm($this->platformAdmin);

    $this->team = Team::factory()->create(['name' => 'Tenant']);
    $this->teamOwner = User::factory()->create(['name' => 'Tenant Owner']);
    $this->teamAdmin = User::factory()->create(['name' => 'Tenant Admin']);
    $this->teamMember = User::factory()->create(['name' => 'Tenant Member']);
    $this->team->members()->attach($this->teamOwner->id, ['role' => 'owner']);
    $this->team->members()->attach($this->teamAdmin->id, ['role' => 'admin']);
    $this->team->members()->attach($this->teamMember->id, ['role' => 'member']);
});

function actAs(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

test('only platform admins may create git sources of any kind', function (string $model) {
    expect($this->platformAdmin->can('create', $model))->toBeTrue()
        ->and($this->teamOwner->can('create', $model))->toBeFalse()
        ->and($this->teamAdmin->can('create', $model))->toBeFalse()
        ->and($this->teamMember->can('create', $model))->toBeFalse();
})->with([GithubApp::class, GitlabApp::class, ForgejoApp::class]);

test('team admins cannot create a gitlab app', function (string $role) {
    actAs($role === 'owner' ? $this->teamOwner : $this->teamAdmin, $this->team);

    Livewire::test(GitlabCreate::class)
        ->set('html_url', 'https://93.184.216.34')
        ->set('api_url', 'https://93.184.216.34/api/v4')
        ->call('createGitLabApp');

    expect(GitlabApp::query()->count())->toBe(0);
})->with(['owner', 'admin']);

test('team admins cannot create a github app', function () {
    actAs($this->teamAdmin, $this->team);

    Livewire::test(GithubCreate::class)
        ->set('html_url', 'https://93.184.216.34')
        ->set('api_url', 'https://93.184.216.34/api')
        ->call('createGitHubApp');

    expect(GithubApp::query()->count())->toBe(0);
});

test('a platform admin can still create a gitlab app', function () {
    actAs($this->platformAdmin, $this->rootTeam);

    Livewire::test(GitlabCreate::class)
        ->set('html_url', 'https://93.184.216.34')
        ->set('api_url', 'https://93.184.216.34/api/v4')
        ->call('createGitLabApp')
        ->assertHasNoErrors();

    expect(GitlabApp::query()->count())->toBe(1);
});

test('the sources page shows the add menu to platform admins only', function () {
    actAs($this->platformAdmin, $this->rootTeam);
    $this->get('/sources')->assertOk()->assertSee('New source');

    foreach ([$this->teamOwner, $this->teamAdmin, $this->teamMember] as $user) {
        actAs($user, $this->team);
        $this->get('/sources')->assertOk()->assertDontSee('New source');
    }
});

test('every team sees the forgejo servers on the sources page', function () {
    ForgejoApp::factory()->create(['name' => 'company-forgejo']);
    $otherTeam = Team::factory()->create();
    $member = User::factory()->create(['name' => 'Other Member']);
    $otherTeam->members()->attach($member->id, ['role' => 'member']);

    foreach ([[$this->teamMember, $this->team], [$member, $otherTeam]] as [$user, $team]) {
        actAs($user, $team);
        $this->get('/sources')->assertOk()->assertSee('company-forgejo')->assertSee('Forgejo');
    }
});

test('the manage link of a forgejo server is shown to platform admins only', function () {
    ForgejoApp::factory()->create(['name' => 'company-forgejo']);

    actAs($this->platformAdmin, $this->rootTeam);
    $this->get('/sources')->assertSee(route('platform.sources'), false);

    actAs($this->teamMember, $this->team);
    $this->get('/sources')->assertDontSee(route('platform.sources'), false);
});

test('forgejo servers of other teams stay hidden', function () {
    ForgejoApp::factory()->create(['name' => 'hidden-forgejo', 'is_system_wide' => false, 'team_id' => Team::factory()->create()->id]);

    actAs($this->teamMember, $this->team);
    $this->get('/sources')->assertOk()->assertDontSee('hidden-forgejo');
});

test('the api refuses a team owner token to create a gitlab app', function () {
    InstanceSettings::query()->whereKey(0)->update(['is_api_enabled' => true]);
    config()->set('app.maintenance.driver', 'file');
    config()->set('cache.default', 'array');
    session(['currentTeam' => $this->team]);
    $token = $this->teamOwner->createToken('owner', ['*']);

    $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken])
        ->postJson('/api/v1/gitlab-apps', ['name' => 'gl', 'html_url' => 'https://93.184.216.34', 'api_url' => 'https://93.184.216.34/api/v4'])
        ->assertForbidden();

    expect(GitlabApp::query()->count())->toBe(0);
});

test('the api lets a platform admin token create a gitlab app', function () {
    InstanceSettings::query()->whereKey(0)->update(['is_api_enabled' => true]);
    config()->set('app.maintenance.driver', 'file');
    config()->set('cache.default', 'array');
    // A platform admin works from the root team, where they are a member.
    session(['currentTeam' => $this->rootTeam]);
    $token = $this->platformAdmin->createToken('admin', ['*']);

    $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken])
        ->postJson('/api/v1/gitlab-apps', ['name' => 'gl', 'html_url' => 'https://93.184.216.34', 'api_url' => 'https://93.184.216.34/api/v4'])
        ->assertCreated();

    expect(GitlabApp::query()->count())->toBe(1);
});
