<?php

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

const INSTANCE_SETTINGS_PATHS = [
    '/settings',
    '/settings/advanced',
    '/settings/updates',
    '/settings/backup',
    '/settings/email',
    '/settings/oauth',
    '/settings/oauth/github',
];

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true, 'is_mcp_server_enabled' => false]);
    Once::flush();

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    Server::factory()->create(['id' => 0, 'team_id' => 0]);

    $this->platformAdmin = User::factory()->create();
    $this->rootTeam->members()->attach($this->platformAdmin->id, ['role' => 'admin']);
    $this->rootMember = User::factory()->create();
    $this->rootTeam->members()->attach($this->rootMember->id, ['role' => 'member']);

    $this->tenant = Team::factory()->create();
    $this->tenantOwner = User::factory()->create();
    $this->tenant->members()->attach($this->tenantOwner->id, ['role' => 'owner']);
});

function signInForSettings(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
    Cache::flush();
}

function rootTeamToken(User $user): string
{
    session(['currentTeam' => Team::find(0)]);
    $token = $user->createToken('instance', ['write']);
    DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['team_id' => '0']);

    return $token->plainTextToken;
}

test('root team members who are not admins cannot open instance settings', function (string $path) {
    signInForSettings($this->rootMember, $this->rootTeam);

    $this->get($path)->assertForbidden();
})->with(INSTANCE_SETTINGS_PATHS);

test('owners of other teams cannot open instance settings', function (string $path) {
    signInForSettings($this->tenantOwner, $this->tenant);

    $this->get($path)->assertForbidden();
})->with(INSTANCE_SETTINGS_PATHS);

test('platform admins can open instance settings', function (string $path) {
    signInForSettings($this->platformAdmin, $this->rootTeam);

    expect($this->get($path)->status())->not->toBe(403);
})->with(INSTANCE_SETTINGS_PATHS);

test('the settings menu is only shown to platform admins', function () {
    signInForSettings($this->rootMember, $this->rootTeam);
    $this->get('/open-source-licenses')->assertOk()->assertDontSee(route('settings.index'), false);

    signInForSettings($this->platformAdmin, $this->rootTeam);
    $this->get('/open-source-licenses')->assertOk()->assertSee(route('settings.index'), false);
});

test('the instance settings policy checks the given user, not the signed-in one', function () {
    signInForSettings($this->platformAdmin, $this->rootTeam);
    $settings = InstanceSettings::query()->find(0);

    expect(Gate::forUser($this->platformAdmin)->allows('update', $settings))->toBeTrue()
        ->and(Gate::forUser($this->rootMember)->allows('update', $settings))->toBeFalse()
        ->and(Gate::forUser($this->rootMember)->allows('view', $settings))->toBeFalse()
        ->and(Gate::forUser($this->tenantOwner)->allows('update', $settings))->toBeFalse();
});

test('a root team member token cannot switch the api or mcp server', function (string $path, string $column, bool $before) {
    InstanceSettings::query()->whereKey(0)->update([$column => $before]);
    Once::flush();

    $this->withHeaders(['Authorization' => 'Bearer '.rootTeamToken($this->rootMember)])
        ->postJson($path)
        ->assertForbidden();

    expect((bool) InstanceSettings::query()->find(0)->{$column})->toBe($before);
})->with([
    ['/api/v1/disable', 'is_api_enabled', true],
    ['/api/v1/mcp/enable', 'is_mcp_server_enabled', false],
    ['/api/v1/mcp/disable', 'is_mcp_server_enabled', true],
]);

test('a platform admin token can switch the api and mcp server', function (string $path, string $column, bool $after) {
    InstanceSettings::query()->whereKey(0)->update([$column => ! $after]);
    Once::flush();

    $this->withHeaders(['Authorization' => 'Bearer '.rootTeamToken($this->platformAdmin)])
        ->postJson($path)
        ->assertOk();

    expect((bool) InstanceSettings::query()->find(0)->{$column})->toBe($after);
})->with([
    ['/api/v1/disable', 'is_api_enabled', false],
    ['/api/v1/mcp/enable', 'is_mcp_server_enabled', true],
    ['/api/v1/mcp/disable', 'is_mcp_server_enabled', false],
]);
