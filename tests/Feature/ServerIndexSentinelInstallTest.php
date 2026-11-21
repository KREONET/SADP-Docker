<?php

use App\Enums\ServerRole;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Jobs\CheckAndStartSentinelJob;
use App\Livewire\Server\Index as ServerIndex;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0, 'fqdn' => 'https://sadp.example.com']));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    makePlatformAdmin($this->user);

    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function sentinelTestServer(Team $team, PrivateKey $key, array $settings = []): Server
{
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $key->id]);
    $server->settings->update(array_merge(['is_reachable' => true, 'is_usable' => true], $settings));

    return $server->fresh();
}

it('installs Sentinel on every validated regular server', function () {
    Queue::fake();
    $first = sentinelTestServer($this->team, $this->privateKey);
    $second = sentinelTestServer($this->team, $this->privateKey);
    sentinelTestServer($this->team, $this->privateKey, ['server_role' => ServerRole::BUILD]);
    sentinelTestServer($this->team, $this->privateKey, ['is_reachable' => false]);

    Livewire::test(ServerIndex::class)
        ->assertSee('Install Sentinel on all servers')
        ->call('installSentinelOnAllServers')
        ->assertDispatched('success');

    Queue::assertPushed(CheckAndStartSentinelJob::class, 2);
    Queue::assertPushed(CheckAndStartSentinelJob::class, fn ($job) => $job->server->is($first));
    Queue::assertPushed(CheckAndStartSentinelJob::class, fn ($job) => $job->server->is($second));
    expect($first->settings->fresh()->sentinel_custom_url)->toBe('https://sadp.example.com');
});

it('hides the bulk Sentinel installation without an installable server', function () {
    sentinelTestServer($this->team, $this->privateKey, ['is_usable' => false]);

    Livewire::test(ServerIndex::class)->assertDontSee('Install Sentinel on all servers');
});

it('does not install Sentinel for a user who cannot manage it', function () {
    Queue::fake();
    sentinelTestServer($this->team, $this->privateKey);
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(ServerIndex::class)
        ->assertDontSee('Install Sentinel on all servers')
        ->call('installSentinelOnAllServers');

    Queue::assertNotPushed(CheckAndStartSentinelJob::class);
});
