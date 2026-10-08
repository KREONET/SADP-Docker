<?php

namespace App\Services\Ssh;

use App\Exceptions\SshHostKeyChangedException;
use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use App\Models\ServerSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Trust-on-first-use (TOFU) SSH host key verification for managed servers.
 *
 * The trusted keys are stored in server_settings (ssh_host_keys,
 * ssh_host_key_fingerprint, ssh_host_key_trusted_at), which is the source of
 * truth. A per-server known_hosts file is written from it so OpenSSH can verify
 * the key itself with StrictHostKeyChecking=yes.
 *
 * Before a key is trusted, OpenSSH connects with StrictHostKeyChecking=accept-new
 * and writes the presented key into the file. The next sync records that key in
 * the database; from then on any other key is refused.
 *
 * Every entry uses HostKeyAlias=coolify-<server uuid>, so the trust follows the
 * server record rather than its address.
 */
class KnownHosts
{
    /**
     * `<host> <key type> <base64 key> [comment]`, without @cert-authority/@revoked markers.
     */
    private const ENTRY_PATTERN = '/^([^\s@#][^\s]*)\s+([A-Za-z0-9@.-]+)\s+([A-Za-z0-9+\/]+={0,3})(?:\s.*)?$/';

    private const SAFE_PATH_PATTERN = '/^\/[A-Za-z0-9._\/-]+$/';

    private const HOST_KEY_FAILURE_PATTERNS = [
        'remote host identification has changed',
        'host key verification failed',
        'and you have requested strict checking',
    ];

    public static function directory(): string
    {
        return rtrim((string) config('security.ssh.known_hosts_directory', storage_path('app/ssh/known_hosts')), '/');
    }

    public static function path(Server $server): string
    {
        $path = self::directory().'/'.$server->uuid;

        if (preg_match(self::SAFE_PATH_PATTERN, $path) !== 1) {
            throw new RuntimeException('The SSH known_hosts path contains unsupported characters.');
        }

        return $path;
    }

    public static function alias(Server $server): string
    {
        return 'coolify-'.$server->uuid;
    }

    /**
     * Host key options for every ssh/scp command to a managed server, ending with a space.
     */
    public static function sshOptions(Server $server): string
    {
        if (! self::hasStableIdentity($server)) {
            // An unsaved server has no record to pin a key to; nothing is trusted or written.
            return '-o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/dev/null -o GlobalKnownHostsFile=/dev/null ';
        }

        $mode = self::sync($server) === [] ? 'accept-new' : 'yes';

        return "-o StrictHostKeyChecking={$mode} "
            .'-o UserKnownHostsFile='.self::path($server).' '
            .'-o GlobalKnownHostsFile=/dev/null '
            .'-o HostKeyAlias='.self::alias($server).' '
            .'-o CheckHostIP=no '
            .'-o UpdateHostKeys=no '
            .'-o HashKnownHosts=no ';
    }

    /**
     * Reconcile the database and the known_hosts file and return the trusted keys.
     *
     * A trusted key in the database is (re)written to the file. Without one, a
     * key that OpenSSH accepted on the first connection is recorded.
     *
     * @return list<array{type: string, key: string}>
     */
    public static function sync(Server $server): array
    {
        if (! self::hasStableIdentity($server)) {
            return [];
        }

        $settings = ServerSetting::query()->where('server_id', $server->id)->first();
        if (! $settings) {
            return [];
        }

        self::ensureDirectory();
        $path = self::path($server);

        $trusted = self::parse((string) $settings->ssh_host_keys);
        if ($trusted !== []) {
            $expected = self::render($server, $trusted);
            if (! is_file($path) || @file_get_contents($path) !== $expected) {
                self::writeFile($path, $expected);
            }

            return $trusted;
        }

        $learned = is_file($path) ? self::parse((string) @file_get_contents($path), self::alias($server)) : [];
        if ($learned === []) {
            return [];
        }

        self::record($server, $settings, $learned);

        return $learned;
    }

    /**
     * Fingerprints of the trusted host keys, e.g. `SHA256:...`.
     *
     * @return list<string>
     */
    public static function trustedFingerprints(Server $server): array
    {
        $fingerprints = ServerSetting::query()->where('server_id', $server->id)->value('ssh_host_key_fingerprint');

        return array_values(array_filter(explode("\n", (string) $fingerprints)));
    }

    /**
     * Forget the trusted key so the next connection learns it again.
     */
    public static function forget(Server $server): void
    {
        if (! self::hasStableIdentity($server)) {
            return;
        }

        ServerSetting::query()->where('server_id', $server->id)->update([
            'ssh_host_keys' => null,
            'ssh_host_key_fingerprint' => null,
            'ssh_host_key_trusted_at' => null,
        ]);
        self::refreshLoadedSettings($server, [
            'ssh_host_keys' => null,
            'ssh_host_key_fingerprint' => null,
            'ssh_host_key_trusted_at' => null,
        ]);

        $path = self::path($server);
        if (is_file($path)) {
            @unlink($path);
        }

        // A multiplexed master was verified against the old key; close it so the next command re-verifies.
        try {
            SshMultiplexingHelper::removeMuxFile($server);
        } catch (Throwable $e) {
            Log::warning('Failed to close the SSH master connection after resetting host key trust', [
                'server_uuid' => $server->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        Cache::forget(self::alertCacheKey($server));
    }

    public static function isHostKeyFailure(string $errorOutput): bool
    {
        return Str::contains(strtolower($errorOutput), self::HOST_KEY_FAILURE_PATTERNS);
    }

    /**
     * Throw a clear error (and alert once per throttle window) when ssh refused
     * the connection because the server presented an untrusted host key.
     *
     * OpenSSH exits with 255 for its own errors; any other exit code came from the
     * remote command, whose output may mention host keys of other machines.
     *
     * @throws SshHostKeyChangedException
     */
    public static function failIfHostKeyChanged(Server $server, string $errorOutput, ?int $exitCode = 255): void
    {
        if ($exitCode !== 255 || ! self::isHostKeyFailure($errorOutput)) {
            return;
        }

        self::reportChangedKey($server, $errorOutput);

        throw new SshHostKeyChangedException($server);
    }

    /**
     * OpenSSH style SHA256 fingerprint of a base64 encoded public key blob.
     */
    public static function fingerprint(string $base64Key): ?string
    {
        $blob = base64_decode($base64Key, true);
        if ($blob === false || $blob === '') {
            return null;
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * Parse known_hosts lines. With $host, only entries for that host are returned.
     *
     * @return list<array{type: string, key: string}>
     */
    public static function parse(string $contents, ?string $host = null): array
    {
        $entries = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Stored keys have no host field.
            $candidate = preg_match('/^[A-Za-z0-9@.-]+\s+[A-Za-z0-9+\/]+={0,3}$/', $line) === 1 ? "- {$line}" : $line;
            if (preg_match(self::ENTRY_PATTERN, $candidate, $matches) !== 1) {
                continue;
            }

            if ($host !== null && ! in_array($host, explode(',', $matches[1]), true)) {
                continue;
            }

            if (self::fingerprint($matches[3]) === null) {
                continue;
            }

            $entries["{$matches[2]} {$matches[3]}"] = ['type' => $matches[2], 'key' => $matches[3]];
        }

        return array_values($entries);
    }

    /**
     * Options for git over SSH (clone, fetch, ls-remote, submodules), without a trailing space.
     *
     * Git runs on the build server or in its helper container, never in the Coolify
     * container, so the known_hosts file lives in the Coolify data directory there.
     */
    public static function gitSshOptions(): string
    {
        $file = (string) SshAlgorithms::setting('git_known_hosts_file', '/data/coolify/ssh/git/known_hosts');
        if (preg_match(self::SAFE_PATH_PATTERN, $file) !== 1) {
            Log::warning('Ignoring invalid git known_hosts path, using the default');
            $file = '/data/coolify/ssh/git/known_hosts';
        }

        return trim('-o StrictHostKeyChecking=accept-new '
            ."-o UserKnownHostsFile={$file} "
            .'-o UpdateHostKeys=no '
            .'-o HashKnownHosts=no '
            .SshAlgorithms::commandLineOptions());
    }

    /**
     * @param  list<array{type: string, key: string}>  $keys
     */
    private static function record(Server $server, ServerSetting $settings, array $keys): void
    {
        $values = [
            'ssh_host_keys' => collect($keys)->map(fn (array $entry): string => "{$entry['type']} {$entry['key']}")->implode("\n"),
            'ssh_host_key_fingerprint' => collect($keys)->map(fn (array $entry): ?string => self::fingerprint($entry['key']))->filter()->implode("\n"),
            'ssh_host_key_trusted_at' => now(),
        ];

        // Only the first writer records the key, so concurrent first connections audit once.
        $updated = ServerSetting::query()
            ->whereKey($settings->id)
            ->whereNull('ssh_host_keys')
            ->update($values);

        if ($updated === 0) {
            return;
        }

        self::refreshLoadedSettings($server, $values);
        self::writeFile(self::path($server), self::render($server, $keys));

        auditLog('server.ssh_host_key.trusted', [
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'server_ip' => $server->ip,
            'fingerprint' => $values['ssh_host_key_fingerprint'],
            'result' => 'success',
        ]);
    }

    private static function reportChangedKey(Server $server, string $errorOutput): void
    {
        try {
            $minutes = max(1, (int) config('security.ssh.host_key_changed_alert_throttle_minutes', 60));
            if (! Cache::add(self::alertCacheKey($server), true, now()->addMinutes($minutes))) {
                return;
            }
        } catch (Throwable) {
            // Without a cache, alert every time rather than never.
        }

        $presented = preg_match('/(SHA256:[A-Za-z0-9+\/]+)/', $errorOutput, $matches) === 1 ? $matches[1] : null;
        $context = [
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'server_ip' => $server->ip,
            'trusted_fingerprint' => implode(', ', self::trustedFingerprints($server)) ?: null,
            'presented_fingerprint' => $presented,
        ];

        auditLog('server.ssh_host_key.changed', $context + ['result' => 'failure'], 'error');
        notifySecurityAlert(
            'ssh_host_key_changed',
            "SSH host key changed for server {$server->name}",
            $context
        );
    }

    private static function alertCacheKey(Server $server): string
    {
        return "ssh-host-key-changed-alert:{$server->uuid}";
    }

    /**
     * @param  list<array{type: string, key: string}>  $keys
     */
    private static function render(Server $server, array $keys): string
    {
        $alias = self::alias($server);

        return collect($keys)->map(fn (array $entry): string => "{$alias} {$entry['type']} {$entry['key']}")->implode("\n")."\n";
    }

    private static function ensureDirectory(): void
    {
        $directory = self::directory();
        File::ensureDirectoryExists($directory, 0700);

        if ((fileperms($directory) & 0777) !== 0700 && ! @chmod($directory, 0700)) {
            Log::warning('Failed to set SSH known_hosts directory permissions to 0700', ['path' => $directory]);
        }
    }

    private static function writeFile(string $path, string $contents): void
    {
        $temporary = $path.'.'.Str::random(8).'.tmp';

        if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write SSH known_hosts file: {$path}");
        }

        @chmod($temporary, 0600);

        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("Failed to write SSH known_hosts file: {$path}");
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function refreshLoadedSettings(Server $server, array $values): void
    {
        if (! $server->relationLoaded('settings') || ! $server->settings) {
            return;
        }

        $server->settings->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }

    private static function hasStableIdentity(Server $server): bool
    {
        return $server->exists
            && filled($server->uuid)
            && preg_match('/^[A-Za-z0-9_-]+$/', (string) $server->uuid) === 1;
    }
}
