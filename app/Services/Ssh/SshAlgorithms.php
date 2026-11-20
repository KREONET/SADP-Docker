<?php

namespace App\Services\Ssh;

use Illuminate\Support\Facades\Log;

/**
 * FIPS 140-3 approved OpenSSH algorithm lists from config('security.ssh').
 *
 * The lists end up in shell commands, so a value that is not a plain
 * comma-separated algorithm list falls back to the built-in FIPS default.
 */
class SshAlgorithms
{
    public const DEFAULT_CIPHERS = 'aes256-gcm@openssh.com,aes128-gcm@openssh.com,aes256-ctr,aes192-ctr,aes128-ctr';

    public const DEFAULT_KEX_ALGORITHMS = 'ecdh-sha2-nistp521,ecdh-sha2-nistp384,ecdh-sha2-nistp256,diffie-hellman-group16-sha512,diffie-hellman-group14-sha256';

    public const DEFAULT_MACS = 'hmac-sha2-512-etm@openssh.com,hmac-sha2-256-etm@openssh.com,hmac-sha2-512,hmac-sha2-256';

    public const DEFAULT_HOST_KEY_ALGORITHMS = 'ecdsa-sha2-nistp521,ecdsa-sha2-nistp384,ecdsa-sha2-nistp256,rsa-sha2-512,rsa-sha2-256';

    public const ED25519 = 'ssh-ed25519';

    /**
     * Plain algorithm names only: no leading +, - or ^ (which would extend the
     * OpenSSH defaults) and no shell metacharacters.
     */
    private const LIST_PATTERN = '/^[A-Za-z0-9@._-]+(,[A-Za-z0-9@._-]+)*$/';

    /**
     * @return array<string, string> OpenSSH option name => algorithm list
     */
    public static function options(): array
    {
        return array_filter([
            'Ciphers' => self::configuredList('ciphers', self::DEFAULT_CIPHERS),
            'KexAlgorithms' => self::configuredList('kex_algorithms', self::DEFAULT_KEX_ALGORITHMS),
            'MACs' => self::configuredList('macs', self::DEFAULT_MACS),
            'HostKeyAlgorithms' => self::hostKeyAlgorithms(),
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * The algorithm options as `-o Name=value ` pairs, ending with a space.
     */
    public static function commandLineOptions(): string
    {
        $options = '';
        foreach (self::options() as $name => $value) {
            $options .= "-o {$name}={$value} ";
        }

        return $options;
    }

    public static function hostKeyAlgorithms(): string
    {
        $configured = self::configuredList('host_key_algorithms', self::DEFAULT_HOST_KEY_ALGORITHMS);
        if ($configured === '') {
            return '';
        }

        $algorithms = collect(explode(',', $configured))
            ->reject(fn (string $algorithm): bool => str_starts_with($algorithm, self::ED25519));

        if (self::setting('allow_ed25519_host_keys', true)) {
            $algorithms->push(self::ED25519);
        }

        if ($algorithms->isEmpty()) {
            return self::DEFAULT_HOST_KEY_ALGORITHMS;
        }

        return $algorithms->unique()->implode(',');
    }

    /**
     * Read config('security.ssh.*'). The git command builders are also used
     * outside a booted application (plain unit tests), where the defaults apply.
     */
    public static function setting(string $key, mixed $default = null): mixed
    {
        if (! app()->bound('config')) {
            return $default;
        }

        return config("security.ssh.{$key}", $default);
    }

    private static function configuredList(string $key, string $default): string
    {
        $value = self::setting($key);

        if ($value === null) {
            return $default;
        }

        $value = str_replace(' ', '', trim((string) $value));
        if ($value === '') {
            return '';
        }

        if (preg_match(self::LIST_PATTERN, $value) !== 1) {
            Log::warning('Ignoring invalid SSH algorithm list, using the FIPS default', ['setting' => "security.ssh.{$key}"]);

            return $default;
        }

        return $value;
    }
}
