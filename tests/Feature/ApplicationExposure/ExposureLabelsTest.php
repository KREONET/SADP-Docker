<?php

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://panel.example.org']);
    Once::flush();
});

/**
 * @param  array<string, mixed>  $access
 * @return array<int, string>
 */
function exposureTraefikLabels(array $access = [], array $options = []): array
{
    return fqdnLabelsForTraefik(...array_merge([
        'uuid' => 'app1',
        'domains' => collect(['https://app.example.org']),
        'onlyPort' => 3000,
        'access' => $access === [] ? null : $access,
    ], $options))->values()->all();
}

/**
 * @param  array<string, mixed>  $access
 * @return array<int, string>
 */
function exposureCaddyLabels(array $access = [], array $options = []): array
{
    return fqdnLabelsForCaddy(...array_merge([
        'network' => 'coolify',
        'uuid' => 'app1',
        'domains' => collect(['https://app.example.org']),
        'onlyPort' => 3000,
        'access' => $access === [] ? null : $access,
    ], $options))->values()->all();
}

function exposureApplication(string $mode, string $proxyType = ProxyTypes::TRAEFIK->value, array $overrides = [], array $serverAttributes = []): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(array_merge(['team_id' => $team->id, 'proxy' => ['type' => $proxyType]], $serverAttributes));
    $server->settings->update(['generate_exact_labels' => true]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();

    return Application::factory()->createOne(array_merge([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://app.example.org',
        'ports_exposes' => '3000',
        'redirect' => 'both',
        'is_http_basic_auth_enabled' => false,
        'exposure_mode' => $mode,
    ], $overrides));
}

it('keeps the public labels identical to labels generated without an exposure setting', function () {
    expect(exposureTraefikLabels(['mode' => 'public']))->toBe(exposureTraefikLabels())
        ->and(exposureCaddyLabels(['mode' => 'public']))->toBe(exposureCaddyLabels());
});

it('allows only the internal network ranges with Traefik, before every other middleware', function () {
    $labels = exposureTraefikLabels(['mode' => 'internal', 'allowed_ranges' => ['10.0.0.0/8', '2001:db8::/32'], 'traefik_ip_middleware' => 'ipallowlist']);

    expect($labels)
        ->toContain('traefik.http.middlewares.internal-only-app1.ipallowlist.sourcerange=10.0.0.0/8,2001:db8::/32')
        ->toContain('traefik.http.routers.https-0-app1.middlewares=internal-only-app1,coolify-hsts,gzip')
        ->toContain('traefik.http.routers.http-0-app1.middlewares=internal-only-app1,gzip');
});

it('uses the ipwhitelist middleware for Traefik before 2.11', function () {
    $labels = exposureTraefikLabels(['mode' => 'internal', 'allowed_ranges' => ['10.0.0.0/8'], 'traefik_ip_middleware' => 'ipwhitelist']);

    expect($labels)->toContain('traefik.http.middlewares.internal-only-app1.ipwhitelist.sourcerange=10.0.0.0/8');
});

it('denies everyone when no internal network range is set', function () {
    expect(exposureTraefikLabels(['mode' => 'internal', 'allowed_ranges' => []]))
        ->toContain('traefik.http.middlewares.internal-only-app1.ipallowlist.sourcerange=255.255.255.255/32')
        ->and(exposureCaddyLabels(['mode' => 'internal', 'allowed_ranges' => []]))
        ->toContain('caddy_0.@outside_internal_network.not.remote_ip=255.255.255.255/32');
});

it('answers 403 outside the internal network with Caddy', function () {
    $labels = exposureCaddyLabels(['mode' => 'internal', 'allowed_ranges' => ['10.0.0.0/8', '192.168.0.0/16']]);

    expect($labels)
        ->toContain('caddy_0.@outside_internal_network.not.remote_ip=10.0.0.0/8 192.168.0.0/16')
        ->toContain('caddy_0.handle_path.0_respond=@outside_internal_network 403')
        ->toContain('caddy_0.handle_path.0_reverse_proxy={{upstreams 3000}}');
});

it('sends signed-in users only requests to the panel with Traefik forward auth', function () {
    $labels = exposureTraefikLabels(['mode' => 'login', 'forward_auth_address' => 'https://panel.example.org/proxy-auth/verify']);

    expect($labels)
        ->toContain('traefik.http.middlewares.signed-in-only-app1.forwardauth.address=https://panel.example.org/proxy-auth/verify')
        ->toContain('traefik.http.middlewares.signed-in-only-app1.forwardauth.authResponseHeaders=X-Paded-User,X-Paded-Email')
        ->toContain('traefik.http.routers.https-0-app1.middlewares=signed-in-only-app1,coolify-hsts,gzip')
        ->not->toContain('traefik.http.middlewares.signed-in-only-app1.forwardauth.trustForwardHeader=true');
});

it('keeps HTTP basic auth after the exposure restriction', function () {
    $labels = exposureTraefikLabels(
        ['mode' => 'login', 'forward_auth_address' => 'https://panel.example.org/proxy-auth/verify'],
        ['is_http_basic_auth_enabled' => true, 'http_basic_auth_username' => 'admin', 'http_basic_auth_password' => 'secret'],
    );

    expect($labels)->toContain('traefik.http.routers.https-0-app1.middlewares=signed-in-only-app1,coolify-hsts,gzip,http-basic-auth-app1');
});

it('sends signed-in users only requests to the panel with Caddy forward_auth', function () {
    $labels = exposureCaddyLabels([
        'mode' => 'login',
        'forward_auth_address_base' => 'https://panel.example.org',
        'forward_auth_uri' => '/proxy-auth/verify',
    ]);

    expect($labels)
        ->toContain('caddy_0.forward_auth=https://panel.example.org')
        ->toContain('caddy_0.forward_auth.uri=/proxy-auth/verify')
        ->toContain('caddy_0.forward_auth.copy_headers=X-Paded-User X-Paded-Email');
});

it('publishes no proxy labels for private applications', function (string $proxyType) {
    $application = exposureApplication('private', $proxyType);

    expect(generateLabelsApplication($application))->toBe([]);
})->with([ProxyTypes::TRAEFIK->value, ProxyTypes::CADDY->value]);

it('passes the internal network ranges from the instance settings to the proxy labels', function () {
    InstanceSettings::find(0)->update(['internal_network_cidrs' => "10.0.0.0/8\n172.16.0.0/12"]);
    Once::flush();

    expect(generateLabelsApplication(exposureApplication('internal')))
        ->toContain('traefik.http.middlewares.internal-only-'.Application::query()->latest('id')->value('uuid').'.ipallowlist.sourcerange=10.0.0.0/8,172.16.0.0/12');
});

it('points the proxy on the Coolify host at the panel over the Docker network', function () {
    $application = exposureApplication('login', ProxyTypes::CADDY->value, serverAttributes: ['id' => 0]);

    expect(applicationProxyAccess($application)['forward_auth_address'])->toBe('http://coolify:8080/proxy-auth/verify');
});

it('points remote proxies at the public panel URL', function () {
    $application = exposureApplication('login');

    expect(applicationProxyAccess($application)['forward_auth_address'])->toBe('https://panel.example.org/proxy-auth/verify');
});

it('accepts only IP addresses and CIDR ranges as internal network ranges', function (string $range, bool $valid) {
    expect(isValidIpOrCidr($range))->toBe($valid);
})->with([
    ['10.0.0.1', true],
    ['10.0.0.0/8', true],
    ['2001:db8::/32', true],
    ['10.0.0.0/33', false],
    ['2001:db8::/129', false],
    ['10.0.0.0/', false],
    ['example.org', false],
    ['10.0.0.0/8/1', false],
]);
