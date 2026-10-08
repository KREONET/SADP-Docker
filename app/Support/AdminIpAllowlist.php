<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * The administrator IP allowlist (`instance_settings.admin_allowed_ips`) and the client
 * address it is checked against.
 *
 * Client address: the app trusts every proxy (TrustProxies, which must not be changed), so
 * `$request->ip()` follows `X-Forwarded-For` even when a client reaches the app directly on
 * its published port and writes that header itself. {@see self::clientIp()} only reads the
 * header when the direct peer is a trusted proxy and then takes the right-most entry that is
 * not itself a trusted proxy, which is the address the outermost trusted proxy saw.
 *
 * Trusted proxies are the ranges in `security.admin_ip.trusted_proxies`
 * (`SECURITY_ADMIN_IP_TRUSTED_PROXIES`, e.g. the address of the Coolify proxy container). When it
 * is empty, every loopback, private or reserved address counts as a trusted proxy. In that default
 * mode a client inside a private network that goes through the proxy can prepend a public address
 * to `X-Forwarded-For`; set the trusted proxies to close that gap.
 *
 * [한국어] 관리자 IP 허용 목록입니다. 직접 연결한 주소가 신뢰하는 프록시일 때만 X-Forwarded-For를 읽고,
 * 그중 오른쪽에서 처음 나오는 신뢰하지 않는 주소를 클라이언트 주소로 씁니다. 신뢰 프록시를 지정하지 않으면
 * 사설/루프백 주소 전체를 신뢰합니다. SECURITY_ADMIN_IP_TRUSTED_PROXIES로 프록시 주소만 지정하면 내부망 위조를 막습니다.
 */
final class AdminIpAllowlist
{
    /**
     * Valid IP addresses and CIDR ranges of the configured allowlist.
     *
     * @return array<int, string>
     */
    public static function ranges(?string $value): array
    {
        return array_values(array_filter(
            parseNetworkRanges($value),
            fn (string $range): bool => isValidIpOrCidr($range),
        ));
    }

    /**
     * Whether the instance restricts administrator access by IP address.
     */
    public static function isConfigured(): bool
    {
        return filled(self::configuredValue());
    }

    /**
     * Valid ranges of the stored instance setting.
     *
     * @return array<int, string>
     */
    public static function configuredRanges(): array
    {
        return self::ranges(self::configuredValue());
    }

    /**
     * Whether the address is inside one of the ranges. An empty range list allows nothing.
     *
     * @param  array<int, string>  $ranges
     */
    public static function allows(?string $ip, array $ranges): bool
    {
        $ip = self::normalize($ip);
        if ($ip === null || $ranges === []) {
            return false;
        }

        return IpUtils::checkIp($ip, $ranges);
    }

    /**
     * The client address used for the administrator allowlist (see the class description).
     */
    public static function clientIp(Request $request): ?string
    {
        $remoteAddress = self::normalize($request->server->get('REMOTE_ADDR'));
        if ($remoteAddress === null || ! self::isTrustedProxy($remoteAddress)) {
            return $remoteAddress;
        }

        $forwarded = self::forwardedFor($request);
        if ($forwarded === null) {
            return $remoteAddress;
        }

        foreach (array_reverse($forwarded) as $entry) {
            if (! self::isTrustedProxy($entry)) {
                return $entry;
            }
        }

        // Every hop is a trusted proxy (a client on the proxy's own network): the proxy appended
        // the right-most entry, so that is the peer it actually saw.
        return $forwarded[array_key_last($forwarded)];
    }

    /**
     * Whether the address belongs to a proxy whose `X-Forwarded-For` entries are trusted:
     * one of the configured trusted proxy ranges, or any private address when none are configured.
     */
    public static function isTrustedProxy(string $ip): bool
    {
        $trustedProxies = self::trustedProxyRanges();
        if ($trustedProxies === []) {
            return self::isPrivate($ip);
        }

        return IpUtils::checkIp($ip, $trustedProxies);
    }

    /**
     * Valid ranges of `security.admin_ip.trusted_proxies` (comma, space or newline separated).
     *
     * @return array<int, string>
     */
    public static function trustedProxyRanges(): array
    {
        $configured = config('security.admin_ip.trusted_proxies');
        if (is_array($configured)) {
            $configured = implode("\n", $configured);
        }

        return self::ranges(is_string($configured) ? $configured : null);
    }

    /**
     * Loopback, private (RFC 1918, ULA) and reserved addresses, including Docker networks.
     */
    public static function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Entries of every `X-Forwarded-For` header in order, or null when a header is missing
     * or holds anything that is not an IP address (the header is then not trusted at all).
     *
     * @return array<int, string>|null
     */
    private static function forwardedFor(Request $request): ?array
    {
        $headers = $request->headers->all('x-forwarded-for');
        if ($headers === []) {
            return null;
        }

        $entries = [];
        foreach ($headers as $header) {
            foreach (explode(',', (string) $header) as $entry) {
                $ip = self::normalize(self::stripPort(trim($entry)));
                if ($ip === null) {
                    return null;
                }
                $entries[] = $ip;
            }
        }

        return $entries === [] ? null : $entries;
    }

    /**
     * Remove a port from `1.2.3.4:5678` or `[2001:db8::1]:5678`.
     */
    private static function stripPort(string $entry): string
    {
        if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $entry, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $entry, $matches) === 1) {
            return $matches[1];
        }

        return $entry;
    }

    /**
     * A valid IP address, with IPv4-mapped IPv6 addresses (`::ffff:1.2.3.4`) turned into IPv4.
     */
    private static function normalize(mixed $ip): ?string
    {
        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $matches) === 1) {
            return $matches[1];
        }

        return $ip;
    }

    private static function configuredValue(): ?string
    {
        try {
            $value = instanceSettings()->admin_allowed_ips;
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
