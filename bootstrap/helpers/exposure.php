<?php

use App\Enums\ApplicationExposureMode;
use App\Models\Application;
use App\Models\Server;

/**
 * Source range used when an "internal network only" application has no ranges to allow:
 * no client can connect from the broadcast address, so the proxy denies everyone.
 */
const EXPOSURE_DENY_ALL_RANGE = '255.255.255.255/32';

/**
 * Splits a comma, space, or newline separated list of IP addresses and CIDR ranges.
 *
 * @return array<int, string>
 */
function parseNetworkRanges(?string $ranges): array
{
    return collect(preg_split('/[\s,]+/', (string) $ranges))
        ->map(fn (string $range) => trim($range))
        ->filter()
        ->unique()
        ->values()
        ->all();
}

function isValidIpOrCidr(string $range): bool
{
    if (filter_var($range, FILTER_VALIDATE_IP)) {
        return true;
    }

    if (substr_count($range, '/') !== 1) {
        return false;
    }

    [$address, $prefix] = explode('/', $range);
    if (! ctype_digit($prefix)) {
        return false;
    }

    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return (int) $prefix <= 32;
    }

    return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && (int) $prefix <= 128;
}

/**
 * Internal network ranges that platform administrators allow for "internal network only" applications.
 *
 * @return array<int, string>
 */
function internalNetworkRanges(): array
{
    try {
        $ranges = instanceSettings()->internal_network_cidrs;
    } catch (Throwable) {
        return [];
    }

    return array_values(array_filter(parseNetworkRanges($ranges), 'isValidIpOrCidr'));
}

/**
 * Public URL of this panel: the instance domain, or the application URL when none is set.
 */
function proxyAuthPanelUrl(): string
{
    try {
        $fqdn = instanceSettings()->fqdn;
    } catch (Throwable) {
        $fqdn = null;
    }

    return rtrim(filled($fqdn) ? $fqdn : (string) config('app.url'), '/');
}

/** Path of the endpoint the proxies call to check "signed-in users only" requests. */
const PROXY_AUTH_VERIFY_PATH = '/proxy-auth/verify';

/**
 * Base URL the proxy of the given server uses to reach this panel.
 * The proxy on the Coolify host reaches the panel over the Docker network, so it works without public DNS.
 */
function proxyAuthPanelUrlForServer(Server $server): string
{
    // A worker routed through the controller is served by the controller's proxy.
    return $server->trafficEntryServer()->id === 0
        ? rtrim((string) config('constants.proxy_auth.internal_url'), '/')
        : proxyAuthPanelUrl();
}

/**
 * Traefik middleware that allows source ranges: `ipallowlist` since Traefik 2.11, `ipwhitelist` before.
 */
function traefikIpAllowListMiddleware(Server $server): string
{
    $version = ltrim((string) $server->trafficEntryServer()->detected_traefik_version, 'v');

    return filled($version) && version_compare($version, '2.11.0', '<') ? 'ipwhitelist' : 'ipallowlist';
}

/**
 * Proxy access settings for an application's exposure mode, passed to the label generators.
 *
 * @return array{mode: string, allowed_ranges: array<int, string>, forward_auth_address: ?string, forward_auth_address_base: ?string, forward_auth_uri: string, traefik_ip_middleware: string}
 */
function applicationProxyAccess(Application $application): array
{
    $mode = $application->exposureMode();
    $server = $application->destination->server;

    return [
        'mode' => $mode->value,
        'allowed_ranges' => $mode === ApplicationExposureMode::Internal ? internalNetworkRanges() : [],
        'forward_auth_address' => $mode === ApplicationExposureMode::Login ? proxyAuthPanelUrlForServer($server).PROXY_AUTH_VERIFY_PATH : null,
        'forward_auth_address_base' => $mode === ApplicationExposureMode::Login ? proxyAuthPanelUrlForServer($server) : null,
        'forward_auth_uri' => PROXY_AUTH_VERIFY_PATH,
        'traefik_ip_middleware' => traefikIpAllowListMiddleware($server),
    ];
}
