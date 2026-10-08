<?php

use App\Livewire\Server\Navbar;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);
});

function makeNavbarServer(bool $isFunctional, bool $platformAdmin = true): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => 'admin']);

    if ($platformAdmin) {
        makePlatformAdmin($user);
    }

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'sentinel_updated_at' => now(),
    ]);

    $server->settings()->update([
        'is_reachable' => $isFunctional,
        'is_usable' => $isFunctional,
        'is_sentinel_enabled' => true,
    ]);

    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return [$server->fresh(), $user, $team];
}

it('does not show sentinel sync status before the server is validated', function () {
    [$server] = makeNavbarServer(isFunctional: false);

    Livewire::test('server.navbar', ['server' => $server])
        ->assertDontSee('Sentinel')
        ->assertDontSee('In sync')
        ->assertDontSee('Out of sync');
});

it('shows sentinel sync status after the server is validated', function () {
    [$server] = makeNavbarServer(isFunctional: true);

    Livewire::test('server.navbar', ['server' => $server])
        ->assertSee('Sentinel')
        ->assertSee('In sync');
});

it('does not show sentinel sync status to a team admin who is not a platform admin', function () {
    [$server, $user] = makeNavbarServer(isFunctional: true, platformAdmin: false);

    expect($user->can('viewSentinel', $server))->toBeFalse();

    Livewire::test('server.navbar', ['server' => $server])
        ->assertOk()
        ->assertDontSee('In sync')
        ->assertDontSee('Out of sync');
});

it('shows the first Sentinel report as pending instead of requiring attention', function () {
    [$server] = makeNavbarServer(isFunctional: true);
    $server->forceFill([
        'sentinel_updated_at' => now()->subDay(),
        'sentinel_waiting_since' => now(),
    ])->save();

    Livewire::test('server.navbar', ['server' => $server->fresh()])
        ->assertSee('Ready')
        ->assertSee('Waiting for first report')
        ->assertDontSee('Attention required')
        ->assertDontSee('Out of sync');
});

it('listens for sentinel status broadcasts', function () {
    [$server, , $team] = makeNavbarServer(isFunctional: true);

    Livewire::test('server.navbar', ['server' => $server])
        ->assertSet('server.uuid', $server->uuid);

    expect(app(Navbar::class)->getListeners())
        ->toHaveKey('sentinel-restart-requested', 'hideSentinelWarning')
        ->toHaveKey("echo-private:team.{$team->id},SentinelRestarted", 'refreshSentinelStatus')
        ->toHaveKey("echo-private:team.{$team->id},SentinelSynchronized", 'refreshSentinelStatus');
});

it('refreshes sentinel status when sentinel restarts for the server', function () {
    [$server] = makeNavbarServer(isFunctional: true);
    $server->forceFill(['sentinel_updated_at' => now()->subDay()])->save();

    expect($server->fresh()->isSentinelLive())->toBeFalse();

    $component = Livewire::test('server.navbar', ['server' => $server->fresh()])
        ->assertSee('Sentinel')
        ->assertSee('Out of sync');

    $server->sentinelHeartbeat();

    $component
        ->call('refreshSentinelStatus', ['serverUuid' => $server->uuid])
        ->assertSee('In sync')
        ->assertDontSee('Out of sync')
        ->assertDispatched('sentinel-status-changed');
});
