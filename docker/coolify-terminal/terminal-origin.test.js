import test from 'node:test';
import assert from 'node:assert/strict';
import { checkTerminalOrigin, getAllowedTerminalOrigins, parseAllowedOrigins } from './terminal-origin.js';

test('parseAllowedOrigins normalizes origins, hosts and wildcards and drops invalid entries', () => {
    assert.deepEqual(
        parseAllowedOrigins(' https://Coolify.Example.com/ , panel.example.org,*.example.net, javascript:alert(1), ftp://x.example, ,*'),
        ['https://coolify.example.com', 'panel.example.org', '*.example.net', '*'],
    );
    assert.deepEqual(parseAllowedOrigins(undefined), []);
});

test('getAllowedTerminalOrigins combines TERMINAL_ALLOWED_ORIGINS with the APP_URL origin', () => {
    assert.deepEqual(getAllowedTerminalOrigins({
        TERMINAL_ALLOWED_ORIGINS: 'https://admin.example.com',
        APP_URL: 'https://coolify.example.com/some/path',
    }), ['https://admin.example.com', 'https://coolify.example.com']);

    assert.deepEqual(getAllowedTerminalOrigins({}), []);
    assert.deepEqual(getAllowedTerminalOrigins({ APP_URL: 'not a url' }), []);
});

test('allows the panel opened on the same host as the terminal socket', () => {
    assert.equal(checkTerminalOrigin({ origin: 'http://203.0.113.10:8000', host: '203.0.113.10:6002' }, []).allowed, true);
    assert.equal(checkTerminalOrigin({ origin: 'https://coolify.example.com', host: 'coolify.example.com' }, []).allowed, true);
    assert.equal(checkTerminalOrigin({ origin: 'http://[2001:db8::1]:8000', host: '[2001:db8::1]:6002' }, []).allowed, true);
});

test('allows configured origins and hosts', () => {
    const allowed = getAllowedTerminalOrigins({
        TERMINAL_ALLOWED_ORIGINS: 'panel.example.org,*.trusted.example',
        APP_URL: 'https://coolify.example.com',
    });

    assert.equal(checkTerminalOrigin({ origin: 'https://coolify.example.com', host: 'terminal.example.com' }, allowed).allowed, true);
    assert.equal(checkTerminalOrigin({ origin: 'http://panel.example.org:8000', host: 'terminal.example.com' }, allowed).allowed, true);
    assert.equal(checkTerminalOrigin({ origin: 'https://a.trusted.example', host: 'terminal.example.com' }, allowed).allowed, true);
    assert.equal(checkTerminalOrigin({ origin: 'https://trusted.example', host: 'terminal.example.com' }, allowed).allowed, false);
    // A configured full origin also pins the scheme and port.
    assert.equal(checkTerminalOrigin({ origin: 'http://coolify.example.com', host: 'terminal.example.com' }, allowed).allowed, false);
});

test('rejects cross-site and sibling-subdomain origins', () => {
    const allowed = getAllowedTerminalOrigins({ APP_URL: 'https://coolify.example.com' });

    assert.deepEqual(
        checkTerminalOrigin({ origin: 'https://evil.example', host: 'coolify.example.com' }, allowed),
        { allowed: false, reason: 'origin not allowed' },
    );
    assert.equal(checkTerminalOrigin({ origin: 'https://app.example.com', host: 'coolify.example.com' }, allowed).allowed, false);
    assert.equal(checkTerminalOrigin({ origin: 'https://coolify.example.com.evil.example', host: 'coolify.example.com' }, allowed).allowed, false);
});

test('rejects missing, opaque and non-HTTP origins', () => {
    for (const origin of [undefined, null, '', 'null', 'file://', 'chrome-extension://abc', 'not a url']) {
        assert.equal(checkTerminalOrigin({ origin, host: 'coolify.example.com' }, []).allowed, false, String(origin));
    }
});

test('a wildcard entry disables the origin check explicitly', () => {
    assert.equal(checkTerminalOrigin({ origin: undefined, host: 'x' }, parseAllowedOrigins('*')).allowed, true);
});
