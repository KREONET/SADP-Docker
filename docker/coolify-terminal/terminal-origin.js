/**
 * Origin checks for terminal WebSocket upgrades (cross-site WebSocket hijacking protection).
 *
 * Browsers always send an Origin header on WebSocket handshakes and attach the panel's
 * session cookie even when another site on the same registrable domain (for example an
 * application deployed on a sibling subdomain) opens the socket. The Origin header is the
 * only reliable signal of which page opened the socket, so the upgrade is accepted only when:
 *
 *   - the Origin matches TERMINAL_ALLOWED_ORIGINS (comma list of origins or host names,
 *     "*.example.com" wildcards allowed, "*" disables the check) or APP_URL, or
 *   - the Origin host is the host the browser connected to (Host header). This covers the
 *     default setups where the panel is opened on http://<ip>:8000 and the terminal on
 *     <ip>:6002, or on the instance domain through the proxy, without extra configuration.
 *
 * A missing Origin is rejected: only browsers connect to this endpoint, and nothing inside
 * the container opens terminal sockets (the healthcheck uses the plain HTTP /ready path).
 */

/**
 * @param {string|undefined|null} value
 * @returns {string[]}
 */
export function parseAllowedOrigins(value) {
    return String(value ?? '')
        .split(',')
        .map((entry) => normalizeAllowedOrigin(entry))
        .filter((entry) => entry !== null);
}

/**
 * @param {Record<string, string|undefined>} environment
 * @returns {string[]}
 */
export function getAllowedTerminalOrigins(environment = process.env) {
    const allowed = parseAllowedOrigins(environment.TERMINAL_ALLOWED_ORIGINS);
    const appOrigin = normalizeAllowedOrigin(environment.APP_URL ?? '');

    if (appOrigin !== null && appOrigin.includes('://')) {
        allowed.push(appOrigin);
    }

    return [...new Set(allowed)];
}

/**
 * @param {{ origin?: string|string[]|null, host?: string|string[]|null }} request
 * @param {string[]} allowedOrigins
 * @returns {{ allowed: boolean, reason: string }}
 */
export function checkTerminalOrigin({ origin, host }, allowedOrigins = []) {
    if (allowedOrigins.includes('*')) {
        return { allowed: true, reason: 'any origin allowed' };
    }

    const originUrl = parseUrl(Array.isArray(origin) ? origin[0] : origin);
    if (originUrl === null || !['http:', 'https:'].includes(originUrl.protocol)) {
        return { allowed: false, reason: 'missing or invalid Origin header' };
    }

    const originHost = originUrl.hostname.toLowerCase();

    for (const entry of allowedOrigins) {
        if (entry.includes('://')) {
            if (entry === originUrl.origin) {
                return { allowed: true, reason: 'configured origin' };
            }
            continue;
        }

        if (hostMatches(entry, originHost)) {
            return { allowed: true, reason: 'configured host' };
        }
    }

    const requestHost = parseUrl(`http://${Array.isArray(host) ? host[0] : (host ?? '')}`);
    if (requestHost !== null && requestHost.hostname !== '' && requestHost.hostname.toLowerCase() === originHost) {
        return { allowed: true, reason: 'same host' };
    }

    return { allowed: false, reason: 'origin not allowed' };
}

/**
 * Turns an entry into "scheme://host[:port]" when it has a scheme, a lower-case host
 * (possibly with a "*." wildcard) when it does not, or "*".
 *
 * @param {string} entry
 * @returns {string|null}
 */
function normalizeAllowedOrigin(entry) {
    const value = String(entry).trim().toLowerCase().replace(/\/+$/, '');

    if (value === '') {
        return null;
    }

    if (value === '*') {
        return '*';
    }

    if (value.includes('://')) {
        const url = parseUrl(value);

        return url !== null && ['http:', 'https:'].includes(url.protocol) ? url.origin : null;
    }

    return /^(\*\.)?[a-z0-9.\-[\]:]+$/.test(value) ? value : null;
}

function hostMatches(pattern, hostname) {
    if (pattern.startsWith('*.')) {
        const suffix = pattern.slice(1);

        return hostname.endsWith(suffix) && hostname.length > suffix.length;
    }

    return pattern === hostname;
}

function parseUrl(value) {
    if (typeof value !== 'string' || value === '' || value === 'null') {
        return null;
    }

    try {
        return new URL(value);
    } catch {
        return null;
    }
}
