<?php

use App\Exceptions\SshHostKeyChangedException;
use App\Helpers\SshMultiplexingHelper;
use App\Livewire\Server\Security\HostKey;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Team;
use App\Models\User;
use App\Notifications\SecurityAlert;
use App\Services\Ssh\KnownHosts;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use phpseclib3\Crypt\EC;

uses(RefreshDatabase::class);

const HOST_KEY_CHANGED_OUTPUT = "@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@\n"
    ."@    WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED!     @\n"
    ."The fingerprint for the ECDSA key sent by the remote host is\n"
    ."SHA256:S8dJOZEtfQRBQ4G3ZU8q8FF6WKADxyCU1c1LxFrxqOg.\n"
    ."Host key for coolify-x has changed and you have requested strict checking.\n"
    .'Host key verification failed.';

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config([
        'app.maintenance.store' => 'array',
        'constants.ssh.mux_enabled' => false,
        'constants.ssh.max_retries' => 3,
        'constants.ssh.retry_base_delay' => 0,
        'security.ssh.known_hosts_directory' => sys_get_temp_dir().'/coolify-known-hosts-'.uniqid(),
    ]);
    Cache::flush();
    Storage::fake('ssh-keys');
    Notification::fake();
    $this->withoutDefer();

    $this->rootTeam = Team::factory()->create(['id' => 0]);
    $this->rootTeam->discordNotificationSettings->update([
        'discord_enabled' => true,
        'discord_webhook_url' => 'https://discord.com/api/webhooks/1/abc',
    ]);
    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'owner']);
    $this->admin->teams()->attach($this->rootTeam, ['role' => 'admin']);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'name' => 'tofu-host',
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
});

afterEach(function () {
    File::deleteDirectory(KnownHosts::directory());
});

/**
 * @return array{type: string, key: string, fingerprint: string}
 */
function hostKeyFixture(): array
{
    $publicKey = EC::createKey('nistp256')->getPublicKey();
    [$type, $key] = explode(' ', trim($publicKey->toString('OpenSSH', ['comment' => ''])));

    return ['type' => $type, 'key' => $key, 'fingerprint' => 'SHA256:'.$publicKey->getFingerprint('sha256')];
}

/**
 * What OpenSSH writes with StrictHostKeyChecking=accept-new and HostKeyAlias.
 */
function simulateAcceptedHostKey(Server $server, array $hostKey): void
{
    File::ensureDirectoryExists(KnownHosts::directory(), 0700);
    file_put_contents(KnownHosts::path($server), KnownHosts::alias($server)." {$hostKey['type']} {$hostKey['key']}\n");
}

function hostKeyAuditEvents(string $event)
{
    return AuditEvent::query()->where('event', $event)->orderBy('id')->get();
}

describe('managed server connections', function () {
    it('connects for the first time with accept-new and a per-server known_hosts file', function () {
        $command = SshMultiplexingHelper::generateSshCommand($this->server, 'echo ok');
        $directory = KnownHosts::directory();

        expect($command)
            ->toContain('-o StrictHostKeyChecking=accept-new ')
            ->toContain("-o UserKnownHostsFile={$directory}/{$this->server->uuid} ")
            ->toContain('-o GlobalKnownHostsFile=/dev/null ')
            ->toContain("-o HostKeyAlias=coolify-{$this->server->uuid} ")
            ->toContain('-o UpdateHostKeys=no ')
            ->not->toContain('StrictHostKeyChecking=no')
            ->not->toContain('UserKnownHostsFile=/dev/null')
            ->and(is_dir($directory))->toBeTrue()
            ->and(fileperms($directory) & 0777)->toBe(0700);
    });

    it('pins FIPS approved algorithms on ssh and scp commands', function () {
        $ssh = SshMultiplexingHelper::generateSshCommand($this->server, 'echo ok');
        $scp = SshMultiplexingHelper::generateScpCommand($this->server, '/tmp/source', '/tmp/dest');

        foreach ([$ssh, $scp] as $command) {
            expect($command)
                ->toContain('-o Ciphers=aes256-gcm@openssh.com,aes128-gcm@openssh.com,aes256-ctr,aes192-ctr,aes128-ctr ')
                ->toContain('-o KexAlgorithms=ecdh-sha2-nistp521,ecdh-sha2-nistp384,ecdh-sha2-nistp256,diffie-hellman-group16-sha512,diffie-hellman-group14-sha256 ')
                ->toContain('-o MACs=hmac-sha2-512-etm@openssh.com,hmac-sha2-256-etm@openssh.com,hmac-sha2-512,hmac-sha2-256 ')
                ->toContain('-o HostKeyAlgorithms=ecdsa-sha2-nistp521,ecdsa-sha2-nistp384,ecdsa-sha2-nistp256,rsa-sha2-512,rsa-sha2-256,ssh-ed25519 ');
        }
    });

    it('records the host key accepted on first use and then requires it', function () {
        $hostKey = hostKeyFixture();
        simulateAcceptedHostKey($this->server, $hostKey);

        $command = SshMultiplexingHelper::generateSshCommand($this->server, 'echo ok');
        $settings = ServerSetting::query()->where('server_id', $this->server->id)->sole();

        expect($command)->toContain('-o StrictHostKeyChecking=yes ')
            ->and($settings->ssh_host_key_fingerprint)->toBe($hostKey['fingerprint'])
            ->and($settings->ssh_host_keys)->toBe("{$hostKey['type']} {$hostKey['key']}")
            ->and($settings->ssh_host_key_trusted_at)->not->toBeNull()
            ->and(fileperms(KnownHosts::path($this->server)) & 0777)->toBe(0600);

        $trusted = hostKeyAuditEvents('server.ssh_host_key.trusted')->sole();
        expect($trusted->metadata)->toMatchArray([
            'server_uuid' => $this->server->uuid,
            'fingerprint' => $hostKey['fingerprint'],
            'result' => 'success',
        ]);

        // A second command neither re-records nor re-audits the key.
        SshMultiplexingHelper::generateSshCommand($this->server, 'echo ok');
        expect(hostKeyAuditEvents('server.ssh_host_key.trusted'))->toHaveCount(1);
    });

    it('computes the same fingerprint as ssh-keygen', function () {
        $hostKey = hostKeyFixture();
        $file = tempnam(sys_get_temp_dir(), 'hostkey');
        file_put_contents($file, "{$hostKey['type']} {$hostKey['key']}\n");

        $output = Process::run(['ssh-keygen', '-lf', $file])->output();
        @unlink($file);

        expect(KnownHosts::fingerprint($hostKey['key']))->toBe($hostKey['fingerprint'])
            ->and($output)->toContain($hostKey['fingerprint']);
    })->skip(fn () => ! is_executable('/usr/bin/ssh-keygen'), 'ssh-keygen is not installed');

    it('restores the known_hosts file from the trusted key in the database', function () {
        $hostKey = hostKeyFixture();
        simulateAcceptedHostKey($this->server, $hostKey);
        KnownHosts::sync($this->server);

        unlink(KnownHosts::path($this->server));
        $command = SshMultiplexingHelper::generateSshCommand($this->server, 'echo ok');

        expect($command)->toContain('-o StrictHostKeyChecking=yes ')
            ->and(file_get_contents(KnownHosts::path($this->server)))
            ->toBe("coolify-{$this->server->uuid} {$hostKey['type']} {$hostKey['key']}\n");
    });

    it('rewrites a tampered known_hosts file from the database', function () {
        $trusted = hostKeyFixture();
        simulateAcceptedHostKey($this->server, $trusted);
        KnownHosts::sync($this->server);

        simulateAcceptedHostKey($this->server, hostKeyFixture());
        KnownHosts::sync($this->server);

        expect(KnownHosts::parse(file_get_contents(KnownHosts::path($this->server))))
            ->toBe([['type' => $trusted['type'], 'key' => $trusted['key']]]);
    });

    it('ignores known_hosts entries for other hosts', function () {
        File::ensureDirectoryExists(KnownHosts::directory());
        $hostKey = hostKeyFixture();
        file_put_contents(KnownHosts::path($this->server), "github.com {$hostKey['type']} {$hostKey['key']}\n");

        expect(KnownHosts::sync($this->server))->toBe([])
            ->and(ServerSetting::query()->where('server_id', $this->server->id)->value('ssh_host_keys'))->toBeNull();
    });
});

describe('changed host keys', function () {
    it('refuses the multiplexed connection with a clear error and alerts once', function () {
        config(['constants.ssh.mux_enabled' => true]);
        $hostKey = hostKeyFixture();
        simulateAcceptedHostKey($this->server, $hostKey);
        KnownHosts::sync($this->server);

        Process::fake([
            '*-O check*' => Process::result(exitCode: 255),
            '*-fN *' => Process::result(errorOutput: HOST_KEY_CHANGED_OUTPUT, exitCode: 255),
        ]);

        expect(fn () => SshMultiplexingHelper::generateSshCommand($this->server, 'echo ok'))
            ->toThrow(SshHostKeyChangedException::class, 'host key changed')
            ->and(fn () => SshMultiplexingHelper::generateSshCommand($this->server, 'echo ok'))
            ->toThrow(SshHostKeyChangedException::class, 're-trust in server settings');

        $changed = hostKeyAuditEvents('server.ssh_host_key.changed')->sole();
        expect($changed->level)->toBe('error')
            ->and($changed->metadata)->toMatchArray([
                'server_uuid' => $this->server->uuid,
                'trusted_fingerprint' => $hostKey['fingerprint'],
                'presented_fingerprint' => 'SHA256:S8dJOZEtfQRBQ4G3ZU8q8FF6WKADxyCU1c1LxFrxqOg',
                'result' => 'failure',
            ]);

        Notification::assertSentTo($this->rootTeam, SecurityAlert::class, fn (SecurityAlert $alert): bool => $alert->type === 'ssh_host_key_changed');
        Notification::assertSentToTimes($this->rootTeam, SecurityAlert::class, 1);
    });

    it('fails instant remote commands on a changed key without retrying', function () {
        Process::fake(['*' => Process::result(errorOutput: HOST_KEY_CHANGED_OUTPUT, exitCode: 255)]);

        expect(fn () => instant_remote_process(['echo ok'], $this->server))
            ->toThrow(SshHostKeyChangedException::class);

        Process::assertRanTimes(fn ($process) => str_contains($process->command, 'ssh '), 1);
        expect(hostKeyAuditEvents('server.ssh_host_key.changed'))->toHaveCount(1);
    });

    it('does not blame the server for host key errors printed by the remote command', function () {
        Process::fake(['*' => Process::result(errorOutput: 'Host key verification failed.', exitCode: 128)]);

        $caught = null;
        try {
            instant_remote_process(['git ls-remote git@example.com:a/b.git'], $this->server);
        } catch (Throwable $e) {
            $caught = $e;
        }

        expect($caught)->toBeInstanceOf(RuntimeException::class)
            ->not->toBeInstanceOf(SshHostKeyChangedException::class)
            ->and(hostKeyAuditEvents('server.ssh_host_key.changed'))->toHaveCount(0);
    });
});

describe('re-trusting a host key', function () {
    it('lets a platform administrator reset the trusted key', function () {
        $hostKey = hostKeyFixture();
        simulateAcceptedHostKey($this->server, $hostKey);
        KnownHosts::sync($this->server);
        Process::fake();

        Livewire::test(HostKey::class, ['server' => $this->server])
            ->assertSee($hostKey['fingerprint'])
            ->call('resetTrust', 'password')
            ->assertSet('fingerprints', [])
            ->assertDispatched('success');

        $settings = ServerSetting::query()->where('server_id', $this->server->id)->sole();
        expect($settings->ssh_host_keys)->toBeNull()
            ->and($settings->ssh_host_key_fingerprint)->toBeNull()
            ->and($settings->ssh_host_key_trusted_at)->toBeNull()
            ->and(file_exists(KnownHosts::path($this->server)))->toBeFalse()
            ->and(SshMultiplexingHelper::generateSshCommand($this->server, 'echo ok'))->toContain('StrictHostKeyChecking=accept-new');

        $reset = hostKeyAuditEvents('server.ssh_host_key.reset')->sole();
        expect($reset->metadata)->toMatchArray([
            'server_uuid' => $this->server->uuid,
            'previous_fingerprint' => $hostKey['fingerprint'],
            'result' => 'success',
        ]);
    });

    it('keeps the trusted key when the password is wrong', function () {
        simulateAcceptedHostKey($this->server, hostKeyFixture());
        KnownHosts::sync($this->server);

        Livewire::test(HostKey::class, ['server' => $this->server])
            ->call('resetTrust', 'wrong-password');

        expect(KnownHosts::trustedFingerprints($this->server))->toHaveCount(1)
            ->and(hostKeyAuditEvents('server.ssh_host_key.reset'))->toHaveCount(0);
    });

    it('forbids team owners who are not platform administrators', function () {
        $owner = User::factory()->create();
        $owner->teams()->attach($this->team, ['role' => 'owner']);
        $this->actingAs($owner);

        Livewire::test(HostKey::class, ['server' => $this->server])->assertForbidden();
    });

    it('denies a reset after the administrator role was removed', function () {
        simulateAcceptedHostKey($this->server, hostKeyFixture());
        KnownHosts::sync($this->server);

        $component = Livewire::test(HostKey::class, ['server' => $this->server]);
        $this->admin->teams()->detach($this->rootTeam);
        $this->admin->unsetRelation('teams');

        $component->call('resetTrust', 'password');

        expect(KnownHosts::trustedFingerprints($this->server))->toHaveCount(1)
            ->and(hostKeyAuditEvents('server.ssh_host_key.reset')->sole()->metadata['result'])->toBe('denied');
    });

    it('hides servers of other teams', function () {
        $otherTeam = Team::factory()->create();
        $otherKey = PrivateKey::factory()->create(['team_id' => $otherTeam->id]);
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id, 'private_key_id' => $otherKey->id]);

        expect(fn () => Livewire::test(HostKey::class, ['server' => $otherServer]))
            ->toThrow(ModelNotFoundException::class);
    });
});
