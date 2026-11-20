<?php

use App\Livewire\Project\Shared\Terminal;
use App\Livewire\Terminal\Index;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\TerminalSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\TwoFactor;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config([
        'app.maintenance.store' => 'array',
        'constants.ssh.mux_enabled' => false,
    ]);
    Cache::flush();

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);

    $this->platformAdmin = User::factory()->create();
    $this->rootTeam->members()->attach($this->platformAdmin->id, ['role' => 'admin']);
    TwoFactor::confirm($this->platformAdmin);
    $this->adminTeam = Team::factory()->create(['name' => 'Admin Personal']);
    $this->adminTeam->members()->attach($this->platformAdmin->id, ['role' => 'owner']);

    $this->tenant = Team::factory()->create(['name' => 'Tenant Team']);
    $this->tenantOwner = User::factory()->create();
    $this->tenant->members()->attach($this->tenantOwner->id, ['role' => 'owner']);

    $this->otherTenant = Team::factory()->create(['name' => 'Other Tenant']);

    $this->tenantServer = terminalScopeServer($this->tenant, 'tenant-server', '192.0.2.10');
    $this->otherServer = terminalScopeServer($this->otherTenant, 'other-server', '192.0.2.20');
});

function terminalScopeServer(Team $team, string $name, string $ip): Server
{
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'name' => $name,
        'ip' => $ip,
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_terminal_enabled' => true,
    ]);

    return $server->fresh();
}

function signInForTerminal(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
    Cache::flush();
}

it('lists every team server on the terminal page for platform admins', function () {
    signInForTerminal($this->platformAdmin, $this->adminTeam);

    $component = Livewire::test(Index::class)->set('isLoadingContainers', false);

    expect($component->get('servers')->pluck('uuid')->all())
        ->toContain($this->tenantServer->uuid)
        ->toContain($this->otherServer->uuid)
        ->and($component->get('showsAllTeams'))->toBeTrue();
    $component->assertSee('tenant-server (Tenant Team)', false);
});

it('lists only the current team servers on the terminal page for team owners', function () {
    signInForTerminal($this->tenantOwner, $this->tenant);

    $component = Livewire::test(Index::class)->set('isLoadingContainers', false);

    expect($component->get('servers')->pluck('uuid')->all())
        ->toBe([$this->tenantServer->uuid])
        ->and($component->get('showsAllTeams'))->toBeFalse();
    $component->assertSee('tenant-server', false)->assertDontSee('other-server', false)->assertDontSee('(Tenant Team)', false);
});

it('does not give root team members terminal access', function () {
    $rootMember = User::factory()->create();
    $this->rootTeam->members()->attach($rootMember->id, ['role' => 'member']);
    signInForTerminal($rootMember, $this->rootTeam);

    $this->get('/terminal')->assertForbidden();
    $this->postJson('/terminal/auth/ips')->assertForbidden();
});

it('returns every terminal server ip to platform admins', function () {
    signInForTerminal($this->platformAdmin, $this->adminTeam);

    expect($this->postJson('/terminal/auth/ips')->assertSuccessful()->json('ipAddresses'))
        ->toContain('192.0.2.10')
        ->toContain('192.0.2.20');
});

it('returns only the current team terminal server ips to team owners', function () {
    signInForTerminal($this->tenantOwner, $this->tenant);

    expect($this->postJson('/terminal/auth/ips')->assertSuccessful()->json('ipAddresses'))
        ->toContain('192.0.2.10')
        ->not->toContain('192.0.2.20');
});

it('issues a terminal token for another team server to platform admins', function () {
    signInForTerminal($this->platformAdmin, $this->adminTeam);

    Livewire::test(Terminal::class)
        ->call('sendTerminalCommand', false, $this->otherServer->uuid, $this->otherServer->uuid)
        ->assertDispatched('send-terminal-token')
        ->assertNotDispatched(Terminal::SESSION_FAILED_EVENT);
});

it('refuses a terminal token for another team server to team owners', function () {
    signInForTerminal($this->tenantOwner, $this->tenant);

    Livewire::test(Terminal::class)
        ->call('sendTerminalCommand', false, $this->otherServer->uuid, $this->otherServer->uuid)
        ->assertNotDispatched('send-terminal-token')
        ->assertDispatched(Terminal::SESSION_FAILED_EVENT, message: Terminal::NOT_ALLOWED_MESSAGE);
});

it('lets platform admins redeem a terminal session on another team server', function () {
    signInForTerminal($this->platformAdmin, $this->adminTeam);
    $token = app(TerminalSessionService::class)->issue($this->platformAdmin, $this->otherServer);

    $response = $this->postJson('/terminal/session', ['token' => $token]);

    $response->assertSuccessful();
    expect($response->json('command'))->toContain("@'192.0.2.20'");
});

it('refuses a terminal session on another team server to team owners', function () {
    signInForTerminal($this->tenantOwner, $this->tenant);
    $token = app(TerminalSessionService::class)->issue($this->tenantOwner, $this->otherServer);

    $this->postJson('/terminal/session', ['token' => $token])->assertNotFound();
});
