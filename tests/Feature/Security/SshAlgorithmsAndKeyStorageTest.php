<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Services\Ssh\SshAlgorithms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

describe('FIPS algorithm configuration', function () {
    it('defaults to FIPS approved algorithms with ssh-ed25519 host keys allowed last', function () {
        expect(SshAlgorithms::options())->toBe([
            'Ciphers' => 'aes256-gcm@openssh.com,aes128-gcm@openssh.com,aes256-ctr,aes192-ctr,aes128-ctr',
            'KexAlgorithms' => 'ecdh-sha2-nistp521,ecdh-sha2-nistp384,ecdh-sha2-nistp256,diffie-hellman-group16-sha512,diffie-hellman-group14-sha256',
            'MACs' => 'hmac-sha2-512-etm@openssh.com,hmac-sha2-256-etm@openssh.com,hmac-sha2-512,hmac-sha2-256',
            'HostKeyAlgorithms' => 'ecdsa-sha2-nistp521,ecdsa-sha2-nistp384,ecdsa-sha2-nistp256,rsa-sha2-512,rsa-sha2-256,ssh-ed25519',
        ]);
    });

    it('keeps the config defaults in sync with the built-in fallbacks', function () {
        expect(config('security.ssh.ciphers'))->toBe(SshAlgorithms::DEFAULT_CIPHERS)
            ->and(config('security.ssh.kex_algorithms'))->toBe(SshAlgorithms::DEFAULT_KEX_ALGORITHMS)
            ->and(config('security.ssh.macs'))->toBe(SshAlgorithms::DEFAULT_MACS)
            ->and(config('security.ssh.host_key_algorithms'))->toBe(SshAlgorithms::DEFAULT_HOST_KEY_ALGORITHMS);
    });

    it('drops ssh-ed25519 host keys when the flag is off', function () {
        config([
            'security.ssh.allow_ed25519_host_keys' => false,
            'security.ssh.host_key_algorithms' => 'ssh-ed25519,ecdsa-sha2-nistp256',
        ]);

        expect(SshAlgorithms::hostKeyAlgorithms())->toBe('ecdsa-sha2-nistp256');
    });

    it('falls back to the FIPS default for lists that could inject options or extend the defaults', function (string $value) {
        config(['security.ssh.ciphers' => $value]);

        expect(SshAlgorithms::options()['Ciphers'])->toBe(SshAlgorithms::DEFAULT_CIPHERS);
    })->with([
        'append to defaults' => ['+3des-cbc'],
        'prepend' => ['^aes128-cbc'],
        'shell metacharacters' => ['aes256-ctr;id'],
        'option injection' => ['aes256-ctr -o ProxyCommand=id'],
        'quotes' => ['aes256-ctr"'],
    ]);

    it('omits a list that is configured empty', function () {
        config(['security.ssh.macs' => '']);

        expect(SshAlgorithms::options())->not->toHaveKey('MACs')
            ->and(SshAlgorithms::commandLineOptions())->not->toContain('MACs=');
    });
});

describe('git host key verification', function () {
    it('verifies git hosts against a persistent known_hosts file in the Coolify data directory', function () {
        expect(gitSshHostKeyOptions())
            ->toStartWith('-o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/data/coolify/ssh/git/known_hosts ')
            ->toContain('-o UpdateHostKeys=no')
            ->toContain('-o Ciphers='.SshAlgorithms::DEFAULT_CIPHERS)
            ->toContain('-o HostKeyAlgorithms=')
            ->not->toContain('StrictHostKeyChecking=no')
            ->not->toContain('/dev/null')
            ->not->toContain('"')
            ->not->toContain("'")
            ->not->toEndWith(' ');
    });

    it('ignores an unsafe git known_hosts path', function () {
        config(['security.ssh.git_known_hosts_file' => '/tmp/known hosts";id;"']);

        expect(gitSshHostKeyOptions())->toContain('-o UserKnownHostsFile=/data/coolify/ssh/git/known_hosts ');
    });

    it('no longer disables host key checking for git anywhere', function (string $file) {
        expect(file_get_contents(base_path($file)))
            ->not->toContain('StrictHostKeyChecking=no')
            ->not->toContain('UserKnownHostsFile=/dev/null');
    })->with([
        'app/Models/Application.php',
        'app/Jobs/ApplicationDeploymentJob.php',
        'app/Helpers/SshMultiplexingHelper.php',
    ]);
});

describe('private key files', function () {
    it('keeps the key directory private and deletes the key file with the key', function () {
        Storage::fake('ssh-keys');
        $team = Team::factory()->create();
        $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);

        $location = $privateKey->storeInFileSystem();
        $directory = dirname($location);
        @chmod($directory, 0755);
        $privateKey->storeInFileSystem();

        expect(fileperms($directory) & 0777)->toBe(0700)
            ->and(fileperms($location) & 0777)->toBe(0600)
            ->and(file_exists("{$location}.lock"))->toBeTrue();

        $privateKey->delete();

        expect(file_exists($location))->toBeFalse()
            ->and(file_exists("{$location}.lock"))->toBeFalse();
    });
});
