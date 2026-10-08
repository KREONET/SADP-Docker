<?php

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Team;
use App\Services\Ssh\KnownHosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->knownHostsDirectory = sys_get_temp_dir().'/coolify-known-hosts-cleanup-'.uniqid();
    config([
        'app.maintenance.store' => 'array',
        'constants.ssh.mux_enabled' => false,
        'security.ssh.known_hosts_directory' => $this->knownHostsDirectory,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->knownHostsDirectory);
});

it('removes the known_hosts file and the trusted host key when a server is force deleted', function () {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $server->settings->forceFill([
        'ssh_host_keys' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl'],
        'ssh_host_key_fingerprint' => 'SHA256:test',
        'ssh_host_key_trusted_at' => now(),
    ])->save();
    $path = KnownHosts::path($server);
    File::ensureDirectoryExists(dirname($path));
    File::put($path, "coolify-{$server->uuid} ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl\n");

    $server->forceDelete();

    expect(is_file($path))->toBeFalse()
        ->and(Server::withTrashed()->find($server->id))->toBeNull()
        ->and(ServerSetting::query()->where('server_id', $server->id)->exists())->toBeFalse();
});

it('keeps the known_hosts file when a server is only soft deleted', function () {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $path = KnownHosts::path($server);
    File::ensureDirectoryExists(dirname($path));
    File::put($path, "coolify-{$server->uuid} ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl\n");

    $server->delete();

    expect(is_file($path))->toBeTrue();
});
