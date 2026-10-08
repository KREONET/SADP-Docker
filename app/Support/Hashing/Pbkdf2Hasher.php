<?php

namespace App\Support\Hashing;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\AbstractHasher;
use RuntimeException;

/**
 * PBKDF2-HMAC-SHA256 password hasher (NIST SP 800-132, FIPS 140-3 / KCMVP approved).
 *
 * Hashes are self-describing: `$pbkdf2-sha256$i=<iterations>$<base64 salt>$<base64 hash>`.
 * Legacy bcrypt hashes still verify, and needsRehash() reports them so they are
 * upgraded the next time the user signs in.
 */
class Pbkdf2Hasher extends AbstractHasher implements Hasher
{
    public const ALGORITHM = 'pbkdf2-sha256';

    public const SALT_BYTES = 32;

    public const HASH_BYTES = 32;

    /**
     * NIST SP 800-132 lower bound; configuration below it is raised to it.
     */
    public const MIN_ITERATIONS = 1000;

    /**
     * Upper bound accepted from a stored hash, so a tampered row cannot pin a CPU.
     */
    public const MAX_ITERATIONS = 10_000_000;

    /**
     * Highest legacy bcrypt cost that is still verified.
     */
    private const MAX_BCRYPT_COST = 14;

    private const PATTERN = '/^\$pbkdf2-sha256\$i=(\d{1,9})\$([A-Za-z0-9+\/]+={0,2})\$([A-Za-z0-9+\/]+={0,2})$/';

    public function __construct(protected int $iterations = 600000)
    {
        $this->iterations = $this->normalizeIterations($iterations);
    }

    /**
     * @return array{algo: string|null, algoName: string, options: array<string, int>}
     */
    public function info($hashedValue): array
    {
        $parsed = $this->parse((string) $hashedValue);
        if ($parsed !== null) {
            return [
                'algo' => self::ALGORITHM,
                'algoName' => self::ALGORITHM,
                'options' => ['iterations' => $parsed['iterations']],
            ];
        }

        return password_get_info((string) $hashedValue);
    }

    /**
     * @param  array{iterations?: int}  $options
     */
    public function make(#[\SensitiveParameter] $value, array $options = []): string
    {
        $iterations = $this->normalizeIterations((int) ($options['iterations'] ?? $this->iterations));
        $salt = random_bytes(self::SALT_BYTES);
        $hash = hash_pbkdf2('sha256', (string) $value, $salt, $iterations, self::HASH_BYTES, true);

        return sprintf('$%s$i=%d$%s$%s', self::ALGORITHM, $iterations, base64_encode($salt), base64_encode($hash));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        if (! is_string($hashedValue) || $hashedValue === '') {
            return false;
        }

        $parsed = $this->parse($hashedValue);
        if ($parsed !== null) {
            $computed = hash_pbkdf2('sha256', (string) $value, $parsed['salt'], $parsed['iterations'], strlen($parsed['hash']), true);

            return hash_equals($parsed['hash'], $computed);
        }

        if ($this->isBcrypt($hashedValue)) {
            return password_verify((string) $value, $hashedValue);
        }

        return false;
    }

    /**
     * Bcrypt hashes and PBKDF2 hashes with fewer iterations than configured need a rehash.
     *
     * @param  array{iterations?: int}  $options
     */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        $parsed = $this->parse((string) $hashedValue);
        if ($parsed === null) {
            return true;
        }

        $iterations = $this->normalizeIterations((int) ($options['iterations'] ?? $this->iterations));

        return $parsed['iterations'] < $iterations || strlen($parsed['salt']) < self::SALT_BYTES;
    }

    /**
     * Used by the `hashed` Eloquent cast: reject unknown formats and hashes more expensive than allowed.
     */
    public function verifyConfiguration($value): bool
    {
        $value = (string) $value;

        // parse() and isBcrypt() already cap the iteration count and the bcrypt cost.
        return $this->parse($value) !== null || $this->isBcrypt($value);
    }

    public function iterations(): int
    {
        return $this->iterations;
    }

    /**
     * @return array{iterations: int, salt: string, hash: string}|null
     */
    private function parse(string $hashedValue): ?array
    {
        if (! str_starts_with($hashedValue, '$'.self::ALGORITHM.'$')
            || preg_match(self::PATTERN, $hashedValue, $matches) !== 1) {
            return null;
        }

        $iterations = (int) $matches[1];
        $salt = base64_decode($matches[2], true);
        $hash = base64_decode($matches[3], true);

        if ($iterations < 1 || $iterations > self::MAX_ITERATIONS
            || $salt === false || strlen($salt) < 16
            || $hash === false || strlen($hash) !== self::HASH_BYTES) {
            return null;
        }

        return ['iterations' => $iterations, 'salt' => $salt, 'hash' => $hash];
    }

    private function isBcrypt(string $hashedValue): bool
    {
        return preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $hashedValue) === 1
            && (int) substr($hashedValue, 4, 2) <= self::MAX_BCRYPT_COST;
    }

    private function normalizeIterations(int $iterations): int
    {
        if ($iterations > self::MAX_ITERATIONS) {
            throw new RuntimeException('PBKDF2 iteration count exceeds the supported maximum.');
        }

        return max(self::MIN_ITERATIONS, $iterations);
    }
}
