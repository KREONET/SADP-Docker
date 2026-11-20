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
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
});

function wildcardLabelApplication(string $fqdn, string $proxyType = ProxyTypes::TRAEFIK->value, bool $dnsChallenge = true): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'proxy' => ['type' => $proxyType]]);
    $server->settings->forceFill([
        'generate_exact_labels' => true,
        'is_dns_challenge_enabled' => $dnsChallenge,
        'dns_challenge_domain' => 'example.com',
        'dns_challenge_provider' => 'cloudflare',
        'dns_challenge_credentials' => ['CF_DNS_API_TOKEN' => 'token'],
    ])->save();
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    // Destinations resolve their server through the Server identity map, which a settings save does not flush.
    Server::flushIdentityMap();

    return Application::factory()->createOne([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => $fqdn,
        'ports_exposes' => '3000',
        'redirect' => 'both',
        'is_http_basic_auth_enabled' => false,
    ])->fresh();
}

/**
 * @return array<int, string>
 */
function certificateLabels(Application $application): array
{
    return collect(generateLabelsApplication($application))
        ->filter(fn ($label) => is_string($label) && str_contains($label, '.tls'))
        ->values()
        ->all();
}

it('covers the base domain and exactly one level below it', function (string $host, bool $covered) {
    expect(wildcardCertificateCovers('example.com', $host))->toBe($covered);
})->with([
    'base domain' => ['example.com', true],
    'direct subdomain' => ['app.example.com', true],
    'upper case' => ['App.Example.COM', true],
    'nested subdomain' => ['a.b.example.com', false],
    'other domain' => ['example.org', false],
    'suffix lookalike' => ['badexample.com', false],
]);

it('requests the wildcard certificate through DNS-01 for a covered domain', function () {
    $application = wildcardLabelApplication('https://app.example.com');
    $router = 'https-0-'.$application->uuid;

    expect(certificateLabels($application))->toEqualCanonicalizing([
        "traefik.http.routers.{$router}.tls=true",
        "traefik.http.routers.{$router}.tls.certresolver=dns",
        "traefik.http.routers.{$router}.tls.domains[0].main=example.com",
        "traefik.http.routers.{$router}.tls.domains[0].sans=*.example.com",
    ]);
});

it('keeps HTTP-01 for domains the wildcard does not cover', function (string $fqdn) {
    $application = wildcardLabelApplication($fqdn);
    $router = 'https-0-'.$application->uuid;

    expect(certificateLabels($application))->toEqualCanonicalizing([
        "traefik.http.routers.{$router}.tls=true",
        "traefik.http.routers.{$router}.tls.certresolver=letsencrypt",
    ]);
})->with([
    'nested subdomain' => ['https://a.b.example.com'],
    'other domain' => ['https://app.example.org'],
]);

it('switches only the covered router of an application with several domains', function () {
    $application = wildcardLabelApplication('https://app.example.com,https://app.example.org');
    $labels = certificateLabels($application);

    expect($labels)->toContain("traefik.http.routers.https-0-{$application->uuid}.tls.certresolver=dns")
        ->toContain("traefik.http.routers.https-1-{$application->uuid}.tls.certresolver=letsencrypt")
        ->not->toContain("traefik.http.routers.https-1-{$application->uuid}.tls.domains[0].main=example.com");
});

it('leaves labels unchanged when DNS-01 is off or the proxy is Caddy', function (string $proxyType, bool $dnsChallenge) {
    $application = wildcardLabelApplication('https://app.example.com', $proxyType, $dnsChallenge);
    $labels = generateLabelsApplication($application);

    $application->destination->server->settings->forceFill(['is_dns_challenge_enabled' => false, 'dns_challenge_domain' => null])->save();
    Server::flushIdentityMap();
    $baseline = generateLabelsApplication($application->fresh());

    expect($labels)->toBe($baseline)
        ->and(collect($labels)->contains(fn ($label) => str_contains((string) $label, 'certresolver=dns')))->toBeFalse();
})->with([
    'traefik with DNS-01 off' => [ProxyTypes::TRAEFIK->value, false],
    'caddy with DNS-01 on' => [ProxyTypes::CADDY->value, true],
]);

it('rewrites keyed compose labels without touching user labels', function () {
    $application = wildcardLabelApplication('https://app.example.com');
    $labels = collect([
        'com.example.owner' => 'team',
        'traefik.http.routers.r1.rule=Host(`api.example.com`) && PathPrefix(`/`)',
        'traefik.http.routers.r1.tls.certresolver=letsencrypt',
    ]);

    $rewritten = applyWildcardCertificateLabels($labels, $application->destination->server)->all();

    expect($rewritten['com.example.owner'])->toBe('team')
        ->and($rewritten)->toContain('traefik.http.routers.r1.tls.certresolver=dns')
        ->toContain('traefik.http.routers.r1.tls.domains[0].main=example.com')
        ->toContain('traefik.http.routers.r1.tls.domains[0].sans=*.example.com')
        ->not->toContain('traefik.http.routers.r1.tls.certresolver=letsencrypt');
});
