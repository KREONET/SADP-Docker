<?php

namespace App\Services\ServerTransfer;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ServerTransferBundle
{
    public const SCHEMA_VERSION = 1;

    public const MAILBOX_DIR = '/data/coolify/exports';

    /**
     * Passphrase envelope format written by encryptWithPassphrase().
     */
    public const ENCRYPTION_VERSION = 2;

    public const KDF = 'pbkdf2-sha256';

    /**
     * Floor for the bundle KDF; bundles leave the instance, so the test-time
     * password hashing override never lowers it.
     */
    public const MIN_KDF_ITERATIONS = 600000;

    public const MAX_KDF_ITERATIONS = 10_000_000;

    public const KDF_SALT_BYTES = 32;

    private const IV_BYTES = 16;

    private const CIPHER = 'AES-256-CBC';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function wrap(array $payload): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at' => now()->toIso8601String(),
            'export_id' => new_public_id(),
        ], $payload);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public static function validate(array $bundle): array
    {
        $errors = [];
        $warnings = [];

        if (! isset($bundle['schema_version'])) {
            $errors[] = 'Missing schema_version.';
        } elseif ((int) $bundle['schema_version'] !== self::SCHEMA_VERSION) {
            $errors[] = 'Unsupported schema_version '.$bundle['schema_version'].'. Expected '.self::SCHEMA_VERSION.'.';
        }

        if (! isset($bundle['export_id']) || ! is_string($bundle['export_id']) || $bundle['export_id'] === '') {
            $errors[] = 'Missing export_id.';
        }

        if (! isset($bundle['server']) || ! is_array($bundle['server'])) {
            $errors[] = 'Missing server payload.';
        } else {
            foreach (['uuid', 'name', 'ip', 'port', 'user'] as $field) {
                if (! array_key_exists($field, $bundle['server'])) {
                    $errors[] = "Missing server.{$field}.";
                }
            }
        }

        if (! isset($bundle['private_key']) || ! is_array($bundle['private_key'])) {
            $errors[] = 'Missing private_key payload.';
        } else {
            if (blank(data_get($bundle, 'private_key.private_key'))) {
                $errors[] = 'Missing private_key.private_key material.';
            }
        }

        if (! isset($bundle['destinations']) || ! is_array($bundle['destinations'])) {
            $errors[] = 'Missing destinations array.';
        } elseif (count($bundle['destinations']) === 0) {
            $warnings[] = 'Bundle has no destinations; a default destination will be used.';
        }

        if (! isset($bundle['projects']) || ! is_array($bundle['projects'])) {
            $errors[] = 'Missing projects array.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    public static function assertValid(array $bundle): void
    {
        $result = self::validate($bundle);
        if (! $result['valid']) {
            throw ValidationException::withMessages([
                'bundle' => $result['errors'],
            ]);
        }
    }

    /**
     * Encrypt an entire bundle for mailbox/API transport with a user passphrase.
     *
     * Envelope v2: PBKDF2-HMAC-SHA256 (random salt, >= 600000 iterations) derives a
     * master key; HKDF-SHA256 splits it into separate AES-256-CBC and HMAC-SHA256 keys.
     * The MAC covers version, salt, iterations, IV and ciphertext (encrypt-then-MAC).
     *
     * @param  array<string, mixed>  $bundle
     * @return array{encrypted: true, schema_version: int, encryption_version: int, kdf: string, iterations: int, salt: string, iv: string, cipher: string, mac: string, payload: string}
     */
    public static function encryptWithPassphrase(array $bundle, string $passphrase): array
    {
        if ($passphrase === '') {
            throw new RuntimeException('Passphrase must not be empty.');
        }

        $json = json_encode($bundle, JSON_THROW_ON_ERROR);
        $iterations = self::kdfIterations();
        $salt = random_bytes(self::KDF_SALT_BYTES);
        $iv = random_bytes(self::IV_BYTES);
        ['encryption' => $encryptionKey, 'mac' => $macKey] = self::deriveKeys($passphrase, $salt, $iterations);

        $cipher = openssl_encrypt($json, self::CIPHER, $encryptionKey, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('Failed to encrypt transfer bundle.');
        }

        $mac = hash_hmac('sha256', self::macInput(self::ENCRYPTION_VERSION, $salt, $iterations, $iv, $cipher), $macKey);

        return [
            'encrypted' => true,
            'schema_version' => self::SCHEMA_VERSION,
            'encryption_version' => self::ENCRYPTION_VERSION,
            'kdf' => self::KDF,
            'iterations' => $iterations,
            'salt' => base64_encode($salt),
            'iv' => base64_encode($iv),
            'cipher' => strtolower(self::CIPHER),
            'mac' => $mac,
            'payload' => base64_encode($cipher),
        ];
    }

    /**
     * Decrypt a passphrase-encrypted bundle. Accepts the current v2 envelope and,
     * read-only, the legacy v1 envelope (unsalted SHA-256 key, no encryption_version).
     *
     * @param  array<string, mixed>  $encrypted
     * @return array<string, mixed>
     */
    public static function decryptWithPassphrase(array $encrypted, string $passphrase): array
    {
        if (! data_get($encrypted, 'encrypted')) {
            throw new RuntimeException('Bundle is not encrypted.');
        }

        $version = data_get($encrypted, 'encryption_version');
        if ($version === null) {
            $json = self::decryptLegacyV1($encrypted, $passphrase);
        } elseif ((int) $version === self::ENCRYPTION_VERSION) {
            $json = self::decryptV2($encrypted, $passphrase);
        } else {
            throw new RuntimeException('Unsupported bundle encryption version.');
        }

        /** @var array<string, mixed> $bundle */
        $bundle = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $bundle;
    }

    /**
     * @param  array<string, mixed>  $encrypted
     */
    private static function decryptV2(array $encrypted, string $passphrase): string
    {
        $iterations = filter_var(data_get($encrypted, 'iterations'), FILTER_VALIDATE_INT);
        $salt = base64_decode((string) data_get($encrypted, 'salt'), true);
        $iv = base64_decode((string) data_get($encrypted, 'iv'), true);
        $cipher = base64_decode((string) data_get($encrypted, 'payload'), true);
        $mac = data_get($encrypted, 'mac');

        if (data_get($encrypted, 'kdf') !== self::KDF
            || strtolower((string) data_get($encrypted, 'cipher', self::CIPHER)) !== strtolower(self::CIPHER)
            || $iterations === false || $iterations < self::MIN_KDF_ITERATIONS || $iterations > self::MAX_KDF_ITERATIONS
            || $salt === false || strlen($salt) !== self::KDF_SALT_BYTES
            || $iv === false || strlen($iv) !== self::IV_BYTES
            || $cipher === false || $cipher === ''
            || ! is_string($mac) || strlen($mac) !== 64) {
            throw new RuntimeException('Invalid encrypted payload.');
        }

        ['encryption' => $encryptionKey, 'mac' => $macKey] = self::deriveKeys($passphrase, $salt, $iterations);

        $expectedMac = hash_hmac('sha256', self::macInput(self::ENCRYPTION_VERSION, $salt, $iterations, $iv, $cipher), $macKey);
        if (! hash_equals($expectedMac, $mac)) {
            throw new RuntimeException('Invalid passphrase or corrupted bundle.');
        }

        $json = openssl_decrypt($cipher, self::CIPHER, $encryptionKey, OPENSSL_RAW_DATA, $iv);
        if ($json === false) {
            throw new RuntimeException('Failed to decrypt transfer bundle.');
        }

        return $json;
    }

    /**
     * Legacy v1 envelope: base64(iv || hex HMAC || ciphertext), key = SHA-256(passphrase).
     * Kept only so bundles exported before v2 can still be imported; never written.
     *
     * @param  array<string, mixed>  $encrypted
     */
    private static function decryptLegacyV1(array $encrypted, string $passphrase): string
    {
        $raw = base64_decode((string) data_get($encrypted, 'payload'), true);
        if ($raw === false || strlen($raw) < 96) {
            throw new RuntimeException('Invalid encrypted payload.');
        }

        $key = hash('sha256', $passphrase, true);
        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 64);
        $cipher = substr($raw, 80);
        $expectedMac = hash_hmac('sha256', $iv.$cipher, $key);
        if (! hash_equals($expectedMac, $mac)) {
            throw new RuntimeException('Invalid passphrase or corrupted bundle.');
        }

        $json = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($json === false) {
            throw new RuntimeException('Failed to decrypt transfer bundle.');
        }

        return $json;
    }

    /**
     * @return array{encryption: string, mac: string}
     */
    private static function deriveKeys(string $passphrase, string $salt, int $iterations): array
    {
        $masterKey = hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);

        return [
            'encryption' => hash_hkdf('sha256', $masterKey, 32, 'server-transfer-bundle:v2:aes-256-cbc', $salt),
            'mac' => hash_hkdf('sha256', $masterKey, 32, 'server-transfer-bundle:v2:hmac-sha256', $salt),
        ];
    }

    private static function macInput(int $version, string $salt, int $iterations, string $iv, string $cipher): string
    {
        return pack('N', $version).$salt.pack('N', $iterations).$iv.$cipher;
    }

    private static function kdfIterations(): int
    {
        return min(self::MAX_KDF_ITERATIONS, max(self::MIN_KDF_ITERATIONS, (int) config('security.password.pbkdf2_iterations')));
    }

    /**
     * Optionally re-seal secrets with Laravel's app key for intermediate storage.
     * Prefer passphrase encryption for cross-instance transfers.
     *
     * @param  array<string, mixed>  $bundle
     */
    public static function sealWithAppKey(array $bundle): string
    {
        return Crypt::encryptString(json_encode($bundle, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public static function unsealWithAppKey(string $sealed): array
    {
        /** @var array<string, mixed> $bundle */
        $bundle = json_decode(Crypt::decryptString($sealed), true, 512, JSON_THROW_ON_ERROR);

        return $bundle;
    }
}
