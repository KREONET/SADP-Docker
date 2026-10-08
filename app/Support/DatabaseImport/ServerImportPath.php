<?php

namespace App\Support\DatabaseImport;

use App\Models\Server;

/**
 * Confines database imports from a server path to an allow-list of base directories.
 *
 * The Coolify backups directory is always allowed; more directories come from
 * `config('database-import.allowed_paths')`. A path is checked as given and again after it is
 * resolved on the server with `realpath`, so symbolic links cannot point outside the allow-list.
 */
class ServerImportPath
{
    private const UNSAFE_PATTERN = '/\.\.|[$()`|;&><\r\n\0\'"\\\\]/';

    /**
     * @return list<string>
     */
    public static function allowedDirectories(): array
    {
        $configured = config('database-import.allowed_paths', []);
        $directories = [backup_dir(), ...(is_array($configured) ? $configured : [])];

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $directory): ?string => self::normalizeDirectory($directory),
            $directories,
        ))));
    }

    /**
     * Checks the path as given, without resolving symbolic links.
     */
    public static function isWithinAllowedDirectory(string $path): bool
    {
        if (! str_starts_with($path, '/') || preg_match(self::UNSAFE_PATTERN, $path)) {
            return false;
        }

        $path = (string) preg_replace('#/+#', '/', $path);
        if (preg_match('#(^|/)\.(/|$)#', $path)) {
            return false;
        }

        foreach (self::allowedDirectories() as $directory) {
            if (str_starts_with($path, $directory.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The canonical path of the file on the server, or null when the file cannot be resolved.
     *
     * @throws DatabaseImportException when the path or its resolved target is outside the allow-list
     */
    public static function resolve(string $path, Server $server): ?string
    {
        if (! self::isWithinAllowedDirectory($path)) {
            throw new DatabaseImportException(self::notAllowedMessage());
        }

        $resolved = trim((string) instant_remote_process(['realpath '.escapeshellarg($path)], $server, throwError: false));
        if ($resolved === '') {
            return null;
        }

        if (str_contains($resolved, "\n") || ! self::isWithinAllowedDirectory($resolved)) {
            throw new DatabaseImportException(self::notAllowedMessage());
        }

        return $resolved;
    }

    public static function notAllowedMessage(): string
    {
        return 'The backup file must be inside an allowed import directory: '.implode(', ', self::allowedDirectories()).'.';
    }

    private static function normalizeDirectory(mixed $directory): ?string
    {
        if (! is_string($directory)) {
            return null;
        }

        $directory = rtrim(trim($directory), '/');
        if ($directory === '' || ! str_starts_with($directory, '/') || preg_match(self::UNSAFE_PATTERN, $directory) || preg_match('#(^|/)\.(/|$)#', $directory)) {
            return null;
        }

        return (string) preg_replace('#/+#', '/', $directory);
    }
}
