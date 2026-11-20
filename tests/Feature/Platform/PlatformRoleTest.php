<?php

use App\Enums\PlatformRole;
use App\Livewire\Platform\Applications;
use App\Livewire\Platform\Dashboard;
use App\Livewire\Platform\Groups;
use App\Livewire\Platform\Security;
use App\Livewire\Platform\Sources;
use App\Livewire\Platform\Users;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);
    Cache::flush();

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->platformAdmin = User::factory()->create();
    $this->rootTeam->members()->attach($this->platformAdmin->id, ['role' => 'admin']);

    $this->tenantTeam = Team::factory()->create(['name' => 'Tenant Team', 'show_boarding' => false]);
    $this->subAdmin = User::factory()->create();
    $this->subAdmin->forceFill(['is_sub_admin' => true])->save();
    $this->tenantTeam->members()->attach($this->subAdmin->id, ['role' => 'member']);

    $this->tenant = User::factory()->create();
    $this->tenantTeam->members()->attach($this->tenant->id, ['role' => 'owner']);
});

function actAsPlatformRoleTestUser(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

test('users have one of three platform roles', function () {
    expect($this->platformAdmin->platformRole())->toBe(PlatformRole::ADMIN)
        ->and($this->subAdmin->platformRole())->toBe(PlatformRole::SUB_ADMIN)
        ->and($this->tenant->platformRole())->toBe(PlatformRole::USER)
        ->and(Gate::forUser($this->platformAdmin)->allows('operatePlatform'))->toBeTrue()
        ->and(Gate::forUser($this->subAdmin)->allows('operatePlatform'))->toBeTrue()
        ->and(Gate::forUser($this->subAdmin)->allows('accessPlatformAdmin'))->toBeFalse()
        ->and(Gate::forUser($this->tenant)->allows('operatePlatform'))->toBeFalse();
});

test('sub-administrators open the operation pages', function (string $path) {
    actAsPlatformRoleTestUser($this->subAdmin, $this->tenantTeam);

    $this->get($path)->assertOk();
})->with(['/platform', '/platform/applications', '/platform/groups', '/platform/users']);

test('sub-administrators cannot open settings, security or logs', function (string $path) {
    actAsPlatformRoleTestUser($this->subAdmin, $this->tenantTeam);

    $this->get($path)->assertForbidden();
})->with(['/platform/sources', '/platform/security', '/platform/logs', '/settings']);

test('sub-administrators pass the operation components and are refused the admin-only ones', function () {
    actAsPlatformRoleTestUser($this->subAdmin, $this->tenantTeam);

    Livewire::test(Dashboard::class)->assertOk();
    Livewire::test(Applications::class)->assertOk();
    Livewire::test(Groups::class)->assertOk();
    Livewire::test(Users::class)->assertOk()->assertDontSeeHtml('makePlatformAdmin(');
    Livewire::test(Sources::class)->assertForbidden();
    Livewire::test(Security::class)->assertForbidden();
});

test('regular users cannot open the users page', function () {
    actAsPlatformRoleTestUser($this->tenant, $this->tenantTeam);

    $this->get('/platform/users')->assertForbidden();
});

test('administrators grant and revoke the administrator role', function () {
    actAsPlatformRoleTestUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Users::class)
        ->assertSeeHtml('makePlatformAdmin('.$this->tenant->id.')')
        ->call('makePlatformAdmin', $this->tenant->id, 'password')
        ->assertReturned(true)
        ->assertDispatched('success');

    $tenant = $this->tenant->fresh();
    expect($tenant->platformRole())->toBe(PlatformRole::ADMIN)
        ->and($tenant->teams()->where('teams.id', 0)->first()->pivot->role)->toBe('admin')
        ->and($tenant->teams()->where('teams.id', $this->tenantTeam->id)->first()->pivot->role)->toBe('owner');

    Livewire::test(Users::class)
        ->call('makePlatformUser', $this->tenant->id, 'password')
        ->assertReturned(true);

    $tenant = $this->tenant->fresh();
    expect($tenant->platformRole())->toBe(PlatformRole::USER)
        ->and($tenant->teams()->where('teams.id', 0)->first()->pivot->role)->toBe('member');
});

test('administrators grant the sub-administrator role and demote administrators to it', function () {
    $otherAdmin = User::factory()->create();
    $this->rootTeam->members()->attach($otherAdmin->id, ['role' => 'admin']);
    actAsPlatformRoleTestUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Users::class)
        ->call('makePlatformSubAdmin', $this->tenant->id, 'password')->assertReturned(true)
        ->call('makePlatformSubAdmin', $otherAdmin->id, 'password')->assertReturned(true);

    $otherAdmin = $otherAdmin->fresh();
    expect($this->tenant->fresh()->platformRole())->toBe(PlatformRole::SUB_ADMIN)
        ->and($otherAdmin->platformRole())->toBe(PlatformRole::SUB_ADMIN)
        ->and($otherAdmin->teams()->where('teams.id', 0)->first()->pivot->role)->toBe('member');

    Livewire::test(Users::class)->call('makePlatformAdmin', $otherAdmin->id, 'password')->assertReturned(true);
    expect((bool) $otherAdmin->fresh()->is_sub_admin)->toBeFalse()
        ->and($otherAdmin->fresh()->platformRole())->toBe(PlatformRole::ADMIN);
});

test('a role change needs the password and refuses the root user and the own account', function () {
    $root = User::find(0) ?? User::factory()->create(['id' => 0]);
    actAsPlatformRoleTestUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Users::class)
        ->call('makePlatformAdmin', $this->tenant->id, 'wrong-password')
        ->assertReturned('The provided password is incorrect.')
        ->call('makePlatformUser', $this->platformAdmin->id, 'password')
        ->assertReturned('You cannot change your own account here.')
        ->call('makePlatformUser', $root->id, 'password')
        ->assertReturned('The root user account cannot be changed here.');

    expect($this->tenant->fresh()->platformRole())->toBe(PlatformRole::USER)
        ->and($this->platformAdmin->fresh()->platformRole())->toBe(PlatformRole::ADMIN);
});

test('sub-administrators cannot change platform roles', function (string $method) {
    actAsPlatformRoleTestUser($this->subAdmin, $this->tenantTeam);

    Livewire::test(Users::class)->call($method, $this->tenant->id, 'password')->assertForbidden();
    expect($this->tenant->fresh()->platformRole())->toBe(PlatformRole::USER);
})->with(['makePlatformAdmin', 'makePlatformSubAdmin']);

test('sub-administrators cannot change root team roles but can change other team roles', function () {
    $rootMember = User::factory()->create();
    $this->rootTeam->members()->attach($rootMember->id, ['role' => 'member']);
    $tenantMember = User::factory()->create();
    $this->tenantTeam->members()->attach($tenantMember->id, ['role' => 'member']);
    actAsPlatformRoleTestUser($this->subAdmin, $this->tenantTeam);

    Livewire::test(Groups::class)->call('updateMemberRole', 0, $rootMember->id, 'admin')->assertForbidden();
    Livewire::test(Groups::class)->call('updateMemberRole', $this->tenantTeam->id, $tenantMember->id, 'admin')->assertDispatched('success');

    expect($rootMember->fresh()->platformRole())->toBe(PlatformRole::USER)
        ->and($tenantMember->fresh()->teams()->where('teams.id', $this->tenantTeam->id)->first()->pivot->role)->toBe('admin');
});

test('the users page filters administrators and sub-administrators', function () {
    actAsPlatformRoleTestUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Users::class)
        ->set('roleFilter', 'sub_admin')
        ->assertSee($this->subAdmin->email)
        ->assertDontSee($this->tenant->email)
        ->set('roleFilter', 'admin')
        ->assertSee($this->platformAdmin->email)
        ->assertDontSee($this->subAdmin->email);
});

test('the groups page counts applications per project without the application relation counts', function () {
    $project = Project::factory()->create(['team_id' => $this->tenantTeam->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $this->tenantTeam->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    foreach (['app-one', 'app-two'] as $name) {
        Application::create([
            'name' => $name,
            'git_repository' => 'https://git.example.com/tenant/app.git',
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
    }
    actAsPlatformRoleTestUser($this->platformAdmin, $this->rootTeam);

    // PostgreSQL rejects the relation count subqueries of the withRelations scope in a grouped query.
    $groupedQueries = [];
    DB::listen(function ($query) use (&$groupedQueries): void {
        if (str_contains($query->sql, 'group by "environments"."project_id"')) {
            $groupedQueries[] = $query->sql;
        }
    });

    Livewire::test(Groups::class)
        ->call('selectTeam', $this->tenantTeam->id)
        ->assertSee('· 2 applications');

    expect($groupedQueries)->not->toBeEmpty();
    foreach ($groupedQueries as $sql) {
        expect($sql)->not->toContain('additional_servers_count')
            ->and($sql)->not->toContain('additional_networks_count');
    }
});
