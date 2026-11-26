<?php

/*
 * Guards for the 2026-11 black-box security assessment (F-01 ~ F-10) and the
 * dependency CVEs fixed with it. These only read packaging files.
 */

use Symfony\Component\Yaml\Yaml;

function productionNginxSite(): string
{
    return file_get_contents(dirname(__DIR__, 2).'/docker/production/etc/nginx/site-opts.d/http.conf');
}

it('hides the nginx and PHP versions', function () {
    expect(productionNginxSite())
        ->toContain('server_tokens off;')
        ->toContain('fastcgi_hide_header X-Powered-By;');
});

it('does not serve the Vite build manifest (F-01 / F-07)', function () {
    expect(productionNginxSite())
        ->toMatch('/location = \/build\/manifest\.json \{[^}]*return 404;/s');
});

it('serves hashed build assets with an immutable cache policy', function () {
    expect(productionNginxSite())
        ->toMatch('/location \^~ \/build\/assets\/ \{[^}]*immutable[^}]*nosniff/s');
});

it('proxies only the Pusher client path and the terminal socket through the panel port (F-03 / F-06)', function (string $site) {
    $contents = file_get_contents(dirname(__DIR__, 2).'/'.$site);

    expect($contents)
        ->toMatch('/location \/app\/ \{[^}]*proxy_pass http:\/\/127\.0\.0\.1:6001;/s')
        ->toMatch('/location = \/terminal\/ws \{[^}]*proxy_pass http:\/\/127\.0\.0\.1:6002;/s')
        ->not->toContain('location /apps');
})->with([
    'production' => ['docker/production/etc/nginx/site-opts.d/http.conf'],
    'development' => ['docker/development/etc/nginx/site-opts.d/http.conf'],
]);

it('publishes the panel, Reverb and the terminal on loopback by default (F-03 / F-06)', function (string $composeFile) {
    $ports = Yaml::parseFile(dirname(__DIR__, 2).'/'.$composeFile)['services']['coolify']['ports'];

    expect($ports)
        ->toContain('${COOLIFY_BIND_ADDRESS:-127.0.0.1}:${APP_PORT:-8000}:8080')
        ->toContain('${COOLIFY_REALTIME_BIND_ADDRESS:-127.0.0.1}:${REVERB_PORT:-${SOKETI_PORT:-6001}}:6001')
        ->toContain('${COOLIFY_REALTIME_BIND_ADDRESS:-127.0.0.1}:6002:6002');
})->with([
    'production compose' => ['docker-compose.prod.yml'],
    'nightly production compose' => ['other/nightly/docker-compose.prod.yml'],
]);

it('connects the terminal through the page port instead of a hard-coded 6002', function () {
    expect(file_get_contents(dirname(__DIR__, 2).'/resources/js/terminal.js'))
        ->toContain("port: window.location.port ? `:\${window.location.port}` : ''")
        ->not->toContain('port: ":6002"');
});

it('keeps the checkout port policy through the first container start', function (string $install, string $upgrade) {
    $root = dirname(__DIR__, 2);
    expect(file_get_contents("$root/$install"))
        ->toContain('cp "$PANEL_COMPOSE" /data/coolify/source/docker-compose.prod.yml')
        ->toContain('cp "$SCRIPT_DIR/upgrade.sh" /data/coolify/source/upgrade.sh');
    expect(file_get_contents("$root/$upgrade"))
        ->not->toContain('$CDN/docker-compose.prod.yml', 'fetch_config_file docker-compose.prod.yml')
        ->not->toContain('set_env_var "COOLIFY_REALTIME_BIND_ADDRESS" "0.0.0.0"');
})->with([
    'stable' => ['scripts/install.sh', 'scripts/upgrade.sh'],
    'nightly' => ['other/nightly/install.sh', 'other/nightly/upgrade.sh'],
]);

it('validates the bind address variables during install', function (string $script) {
    expect(file_get_contents(dirname(__DIR__, 2).'/'.$script))
        ->toContain('for BIND_VAR in COOLIFY_BIND_ADDRESS COOLIFY_REALTIME_BIND_ADDRESS; do')
        ->toContain('Ignoring invalid $BIND_VAR');
})->with([
    'install script' => ['scripts/install.sh'],
    'nightly install script' => ['other/nightly/install.sh'],
]);

it('pins patched runtime versions in the production image', function () {
    $dockerfile = file_get_contents(dirname(__DIR__, 2).'/docker/production/Dockerfile');

    preg_match('/^ARG NGINX_VERSION=1\.31\.(\d+)-r\d+$/m', $dockerfile, $nginx);
    preg_match('/^ARG CLOUDFLARED_VERSION=(\d{4}\.\d+\.\d+)$/m', $dockerfile, $cloudflared);
    preg_match('/^ARG SERVERSIDEUP_PHP_VERSION=8\.4\.(\d+)-/m', $dockerfile, $php);

    // nginx 1.31.7: CVE-2026-15176/93479/95101/97205/97629. cloudflared 2026.10.3: eMudhra CA compromise.
    // PHP 8.4.27: CVE-2026-109618/109619 (PHP2Shell).
    expect((int) $nginx[1])->toBeGreaterThanOrEqual(7)
        ->and(version_compare($cloudflared[1], '2026.10.3', '>='))->toBeTrue()
        ->and((int) $php[1])->toBeGreaterThanOrEqual(27)
        ->and($dockerfile)->not->toContain('FROM node:24-alpine');
});

it('verifies the cloudflared download checksum', function () {
    expect(file_get_contents(dirname(__DIR__, 2).'/docker/production/Dockerfile'))
        ->toMatch('/^ARG CLOUDFLARED_SHA256_AMD64=[0-9a-f]{64}$/m')
        ->toMatch('/^ARG CLOUDFLARED_SHA256_ARM64=[0-9a-f]{64}$/m')
        ->toContain('sha256sum -c -')
        ->toContain('curl -fsSL "https://github.com/cloudflare/cloudflared/releases/download/');
});
