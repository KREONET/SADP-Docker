<?php

namespace App\Support;

/**
 * Credentials and allowed origins of the bundled Reverb server.
 *
 * Used from config/reverb.php and config/broadcasting.php, so it only works with plain
 * values (env strings) and never touches the container or the database.
 */
class ReverbSettings
{
    /**
     * Environments that keep the well-known "coolify" development credentials.
     *
     * @var array<int, string>
     */
    public const DEVELOPMENT_ENVIRONMENTS = ['local', 'development', 'testing'];

    public const DEVELOPMENT_SECRET = 'coolify';

    /**
     * The app secret signs private channel authorizations and server-side API calls.
     *
     * Outside development a missing PUSHER_APP_SECRET no longer falls back to the public
     * "coolify" value: it is derived from APP_KEY instead, so it is unique per install and
     * identical for every process of the instance (PHP-FPM, Horizon, Reverb) without
     * breaking broadcasts. The installer and upgrade script still write a random secret.
     */
    public static function secret(?string $configuredSecret, ?string $environment, ?string $appKey): string
    {
        if (filled($configuredSecret)) {
            return $configuredSecret;
        }

        if (self::isDevelopment($environment)) {
            return self::DEVELOPMENT_SECRET;
        }

        // Without APP_KEY nothing can be derived: use an unguessable per-process value so
        // the broadcaster still boots (artisan keeps working) but nothing can sign with it.
        if (blank($appKey)) {
            return bin2hex(random_bytes(32));
        }

        return hash_hmac('sha256', 'coolify-reverb-app-secret', $appKey);
    }

    /**
     * Host names that may open a Reverb connection (Reverb matches the Origin host with Str::is).
     *
     * - REVERB_ALLOWED_ORIGINS (comma list of host names, URLs or "*.example.com" patterns) wins.
     * - Otherwise the hosts of APP_URL and of the instance domain are allowed.
     * - Without any configured address (the default install has no APP_URL, and the panel is
     *   opened on its IP address) or in development, every origin stays allowed, as before.
     *   Private channels are still protected by the authenticated /broadcasting/auth endpoint.
     *
     * @param  array<int, string|null>  $instanceUrls
     * @return array<int, string>
     */
    public static function allowedOrigins(?string $override, ?string $appUrl, ?string $environment, array $instanceUrls = []): array
    {
        $configured = self::parseOrigins((string) $override);
        if ($configured !== []) {
            return $configured;
        }

        if (self::isDevelopment($environment) || blank($appUrl)) {
            return ['*'];
        }

        $derived = self::parseOrigins(implode(',', array_filter([$appUrl, ...$instanceUrls], 'filled')));

        return $derived !== [] ? $derived : ['*'];
    }

    /**
     * Adds instance domains (for example the FQDN saved in the settings) to resolved origins.
     *
     * @param  array<int, string>  $origins
     * @param  array<int, string|null>  $urls
     * @return array<int, string>
     */
    public static function withInstanceUrls(array $origins, array $urls): array
    {
        if (in_array('*', $origins, true)) {
            return $origins;
        }

        return array_values(array_unique([...$origins, ...self::parseOrigins(implode(',', array_filter($urls, 'filled')))]));
    }

    /**
     * @return array<int, string>
     */
    public static function parseOrigins(string $value): array
    {
        $hosts = [];

        foreach (explode(',', $value) as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === '') {
                continue;
            }

            if ($entry === '*') {
                return ['*'];
            }

            $host = str_contains($entry, '://') ? parse_url($entry, PHP_URL_HOST) : explode('/', $entry)[0];
            if (! is_string($host) || $host === '') {
                continue;
            }

            // Drop a port ("example.com:8000", "[2001:db8::1]:8000"); Reverb compares host names only.
            if (preg_match('/^(\[[0-9a-f:.]+\])(:\d+)?$/', $host, $matches) === 1) {
                $hosts[] = $matches[1];
            } elseif (preg_match('/^((\*\.)?[a-z0-9.\-]+)(:\d+)?$/', $host, $matches) === 1) {
                $hosts[] = $matches[1];
            }
        }

        return array_values(array_unique($hosts));
    }

    private static function isDevelopment(?string $environment): bool
    {
        return in_array(strtolower((string) $environment), self::DEVELOPMENT_ENVIRONMENTS, true);
    }
}
