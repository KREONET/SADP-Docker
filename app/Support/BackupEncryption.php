<?php

namespace App\Support;

/**
 * Remote shell commands that encrypt and decrypt database backup files with OpenSSL.
 *
 * The format is `openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -salt -md sha256` (FIPS-approved
 * primitives). The passphrase is a random value per backup file. It is written to the stdin of
 * openssl by the shell builtin `echo`, so it never appears in process arguments (`ps`). The
 * non-root sudo parser keeps `echo` as a builtin and only elevates `openssl`.
 *
 * The first line of every command list is a comment that is longer than the command preview
 * that SSH retry logging keeps, so the passphrase never reaches the logs.
 */
class BackupEncryption
{
    public const EXTENSION = '.enc';

    public const PBKDF2_ITERATIONS = 600000;

    private const LOG_SAFE_HEADER = '# Coolify backup encryption: the passphrase is passed on stdin, never as a process argument or in logs.';

    public static function generatePassphrase(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isEncryptedFilename(?string $filename): bool
    {
        return is_string($filename) && str_ends_with($filename, self::EXTENSION);
    }

    /**
     * The file name without the `.enc` suffix.
     */
    public static function plainFilename(string $filename): string
    {
        return self::isEncryptedFilename($filename) ? substr($filename, 0, -strlen(self::EXTENSION)) : $filename;
    }

    public static function opensslOptions(): string
    {
        return '-aes-256-cbc -pbkdf2 -iter '.self::PBKDF2_ITERATIONS.' -salt -md sha256';
    }

    /**
     * Encrypts a backup file into `$encryptedPath` and removes the plain file.
     *
     * @return list<string>
     */
    public static function encryptCommands(string $plainPath, string $encryptedPath, string $passphrase): array
    {
        self::assertPassphrase($passphrase);

        return [
            self::LOG_SAFE_HEADER,
            'rm -f '.escapeshellarg($encryptedPath),
            'echo '.escapeshellarg($passphrase).' | openssl enc '.self::opensslOptions()
                .' -in '.escapeshellarg($plainPath).' -out '.escapeshellarg($encryptedPath).' -pass stdin',
            'rm -f '.escapeshellarg($plainPath),
        ];
    }

    /**
     * Decrypts a backup file into `$plainPath`. The plain file is created with mode 0600 first,
     * so other users on the server cannot read the decrypted dump.
     *
     * @return list<string>
     */
    public static function decryptCommands(string $encryptedPath, string $plainPath, string $passphrase): array
    {
        self::assertPassphrase($passphrase);

        return [
            self::LOG_SAFE_HEADER,
            'install -m 600 /dev/null '.escapeshellarg($plainPath),
            'echo '.escapeshellarg($passphrase).' | openssl enc -d '.self::opensslOptions()
                .' -in '.escapeshellarg($encryptedPath).' -out '.escapeshellarg($plainPath).' -pass stdin',
        ];
    }

    public static function opensslCheckCommand(): string
    {
        return 'command -v openssl';
    }

    /**
     * Generated passphrases are hex, so `echo` prints them unchanged in every shell.
     */
    private static function assertPassphrase(string $passphrase): void
    {
        if (! preg_match('/^[a-f0-9]{32,128}$/', $passphrase)) {
            throw new \InvalidArgumentException('Invalid backup encryption passphrase.');
        }
    }
}
