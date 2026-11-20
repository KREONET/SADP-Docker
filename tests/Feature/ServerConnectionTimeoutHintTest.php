<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config([
        'constants.ssh.mux_enabled' => false,
        'constants.ssh.max_retries' => 1,
    ]);
    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

function timeoutHintServer(array $attributes): Server
{
    return Server::factory()->create(array_merge([
        'team_id' => test()->team->id,
        'private_key_id' => test()->privateKey->id,
        'port' => 22,
    ], $attributes));
}

it('tells how to allow SSH through the host firewall when the Coolify host times out', function () {
    $server = timeoutHintServer(['ip' => 'host.docker.internal']);

    expect($server->connectionErrorHint('ssh: connect to host host.docker.internal port 22: Operation timed out'))
        ->toContain('sudo ufw allow from')
        ->toContain("docker network inspect coolify -f '{{range .IPAM.Config}}{{.Subnet}}{{end}}'")
        ->toContain('to any port 22 proto tcp');
});

it('points remote server timeouts at the server firewall', function () {
    $server = timeoutHintServer(['ip' => '192.0.2.40', 'port' => 2222]);

    expect($server->connectionErrorHint('ssh: connect to host 192.0.2.40 port 2222: Connection timed out'))
        ->toBe('Port 2222 on 192.0.2.40 did not answer. Make sure the server firewall allows SSH from this Coolify server.');
});

it('adds no hint to errors other than timeouts', function () {
    $server = timeoutHintServer(['ip' => 'host.docker.internal']);

    expect($server->connectionErrorHint('Permission denied (publickey).'))->toBeNull();
});

it('includes the firewall hint in the connection check result', function () {
    Process::fake(fn () => Process::result(errorOutput: 'ssh: connect to host host.docker.internal port 22: Operation timed out', exitCode: 255));
    $server = timeoutHintServer(['ip' => 'host.docker.internal']);

    $result = $server->validateConnection();

    expect($result['uptime'])->toBeFalse()
        ->and($result['error'])->toContain('Operation timed out')
        ->and($result['error'])->toContain('sudo ufw allow from');
});
