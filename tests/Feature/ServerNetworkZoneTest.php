<?php

use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Livewire\Server\Index as ServerIndex;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

function networkTestServer(Team $team, PrivateKey $key, string $ip, string $name = 'server'): Server
{
    return Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $key->id, 'ip' => $ip, 'name' => $name]);
}

it('classifies server addresses as internal or external', function (string $address, bool $internal) {
    expect(Server::isInternalAddress($address))->toBe($internal);
})->with([
    'rfc 1918 10/8' => ['10.1.2.3', true],
    'rfc 1918 172.16/12' => ['172.20.0.5', true],
    'rfc 1918 192.168/16' => ['192.168.0.10', true],
    'cgnat / tailscale' => ['100.101.102.103', true],
    'ipv6 unique local' => ['fd12:3456::1', true],
    'internal host name' => ['db01.internal', true],
    'single label host name' => ['nas', true],
    'public ipv4' => ['203.0.113.45', false],
    'public ipv6' => ['2001:4860:4860::8888', false],
    'just outside 172.16/12' => ['172.32.0.1', false],
]);

it('treats addresses in the configured internal network ranges as internal', function () {
    expect(Server::isInternalAddress('203.0.113.45'))->toBeFalse();

    InstanceSettings::get()->update(['internal_network_cidrs' => '203.0.113.0/24']);

    expect(Server::isInternalAddress('203.0.113.45'))->toBeTrue();
});

it('reports internal servers to the internal URL and external servers to the instance URL', function () {
    InstanceSettings::get()->update(['internal_url' => 'http://192.168.0.2:8000']);
    $internal = networkTestServer($this->team, $this->privateKey, '192.168.0.20');
    $external = networkTestServer($this->team, $this->privateKey, '203.0.113.45');

    expect($internal->settings->generateSentinelUrl())->toBe('http://192.168.0.2:8000')
        ->and($external->settings->generateSentinelUrl())->toBe('https://sadp.example.com');
});

it('reports every server to the instance URL without an internal URL', function () {
    $internal = networkTestServer($this->team, $this->privateKey, '192.168.0.20');

    expect($internal->settings->generateSentinelUrl())->toBe('https://sadp.example.com');
});

it('moves internal servers to a new internal URL and back', function () {
    $internal = networkTestServer($this->team, $this->privateKey, '10.0.0.5');
    $external = networkTestServer($this->team, $this->privateKey, '203.0.113.45');
    $custom = networkTestServer($this->team, $this->privateKey, '10.0.0.6');
    foreach ([$internal, $external] as $server) {
        $server->settings->forceFill(['sentinel_custom_url' => 'https://sadp.example.com', 'is_sentinel_enabled' => false])->saveQuietly();
    }
    $custom->settings->forceFill(['sentinel_custom_url' => 'http://my-own-url:8000', 'is_sentinel_enabled' => false])->saveQuietly();

    InstanceSettings::get()->update(['internal_url' => 'http://10.0.0.2:8000']);

    expect($internal->settings->fresh()->sentinel_custom_url)->toBe('http://10.0.0.2:8000')
        ->and($external->settings->fresh()->sentinel_custom_url)->toBe('https://sadp.example.com')
        ->and($custom->settings->fresh()->sentinel_custom_url)->toBe('http://my-own-url:8000');

    InstanceSettings::get()->update(['internal_url' => null]);

    expect($internal->settings->fresh()->sentinel_custom_url)->toBe('https://sadp.example.com');
});

it('moves an internal server from the legacy public IP URL to its configured internal URL', function () {
    $settings = InstanceSettings::get();
    $settings->update(['fqdn' => null, 'public_ipv4' => '203.0.113.10']);
    $internal = networkTestServer($this->team, $this->privateKey, '10.0.0.5');
    $internal->settings->forceFill(['sentinel_custom_url' => 'http://203.0.113.10:8000', 'is_sentinel_enabled' => false])->saveQuietly();

    $settings->update(['internal_url' => 'https://panel.internal.example']);

    expect($internal->settings->fresh()->sentinel_custom_url)->toBe('https://panel.internal.example');
});

it('saves the internal URL in the settings', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id, 'ip' => 'host.docker.internal']);

    Livewire::test(SettingsIndex::class)
        ->set('internal_url', 'http://192.168.0.2:8000/')
        ->call('saveInternalNetworks')
        ->assertHasNoErrors();

    expect(InstanceSettings::get()->internal_url)->toBe('http://192.168.0.2:8000');

    Livewire::test(SettingsIndex::class)
        ->set('internal_url', 'not a url')
        ->call('saveInternalNetworks')
        ->assertHasErrors('internal_url');
});

it('shows the network of each server in the server list', function () {
    networkTestServer($this->team, $this->privateKey, '192.168.0.20', 'office-server');
    networkTestServer($this->team, $this->privateKey, '203.0.113.45', 'cloud-server');

    Livewire::test(ServerIndex::class)
        ->assertSee('data-network-filter', false)
        ->assertSee('data-network-badge="internal"', false)
        ->assertSee('data-network-badge="external"', false)
        ->assertDontSee('192.168.0.20')
        ->assertDontSee('203.0.113.45');
});
