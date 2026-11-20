<?php

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Actions\Server\ApplyDnsChallenge;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = User::factory()->create()->teams()->first();
});

/**
 * @param  array<string, mixed>  $settings
 */
function dnsChallengeServer(object $test, array $settings = [], string $proxyType = 'TRAEFIK'): Server
{
    $server = Server::factory()->create(['team_id' => $test->team->id]);
    $server->proxy->set('type', $proxyType);
    $server->save();
    $server->settings->forceFill(array_merge([
        'is_dns_challenge_enabled' => true,
        'dns_challenge_domain' => 'example.com',
        'dns_challenge_provider' => 'cloudflare',
        'dns_challenge_credentials' => ['CF_DNS_API_TOKEN' => 'cf-secret-token'],
        'dns_challenge_email' => 'ops@example.com',
    ], $settings))->save();

    return $server->fresh();
}

/**
 * @return array<string, mixed>
 */
function generatedTraefikService(Server $server): array
{
    SaveProxyConfiguration::partialMock()->shouldReceive('handle')->once();

    return Yaml::parse(generateDefaultProxyConfiguration($server))['services']['traefik'];
}

it('adds the dns resolver and provider credentials to the generated Traefik configuration', function (string $provider, array $credentials) {
    $server = dnsChallengeServer($this, [
        'dns_challenge_provider' => $provider,
        'dns_challenge_credentials' => $credentials,
    ]);

    $traefik = generatedTraefikService($server);

    expect($traefik['command'])
        ->toContain('--certificatesresolvers.letsencrypt.acme.httpchallenge=true')
        ->toContain('--certificatesresolvers.letsencrypt.acme.storage=/traefik/acme.json')
        ->toContain('--certificatesresolvers.dns.acme.dnschallenge=true')
        ->toContain("--certificatesresolvers.dns.acme.dnschallenge.provider={$provider}")
        ->toContain('--certificatesresolvers.dns.acme.dnschallenge.resolvers=1.1.1.1:53,8.8.8.8:53')
        ->toContain('--certificatesresolvers.dns.acme.storage=/traefik/acme-dns.json')
        ->toContain('--certificatesresolvers.dns.acme.email=ops@example.com')
        ->and($traefik['environment'])->toBe($credentials);
})->with([
    'cloudflare' => ['cloudflare', ['CF_DNS_API_TOKEN' => 'cf-secret-token']],
    'route53' => ['route53', ['AWS_ACCESS_KEY_ID' => 'AKIA123', 'AWS_SECRET_ACCESS_KEY' => 'aws-secret', 'AWS_REGION' => 'ap-northeast-2']],
    'rfc2136' => ['rfc2136', ['RFC2136_NAMESERVER' => 'ns1.example.com:53', 'RFC2136_TSIG_KEY' => 'paded', 'RFC2136_TSIG_SECRET' => 'tsig-secret==', 'RFC2136_TSIG_ALGORITHM' => 'hmac-sha256.']],
    'other lego provider' => ['gandiv5', ['GANDIV5_PERSONAL_ACCESS_TOKEN' => 'gandi-token']],
]);

it('uses custom resolvers and leaves out the email when none is set', function () {
    $server = dnsChallengeServer($this, ['dns_challenge_resolvers' => '9.9.9.9:53', 'dns_challenge_email' => null]);

    $commands = generatedTraefikService($server)['command'];

    expect($commands)->toContain('--certificatesresolvers.dns.acme.dnschallenge.resolvers=9.9.9.9:53')
        ->and(collect($commands)->filter(fn (string $command) => str_starts_with($command, '--certificatesresolvers.dns.acme.email')))->toBeEmpty();
});

it('generates no dns resolver or credentials when DNS-01 is off or incomplete', function (array $settings) {
    $traefik = generatedTraefikService(dnsChallengeServer($this, $settings));

    expect(collect($traefik['command'])->filter(fn (string $command) => str_starts_with($command, '--certificatesresolvers.dns.')))->toBeEmpty()
        ->and($traefik)->not->toHaveKey('environment');
})->with([
    'disabled' => [['is_dns_challenge_enabled' => false]],
    'no domain' => [['dns_challenge_domain' => null]],
    'no provider' => [['dns_challenge_provider' => null]],
]);

it('keeps custom configuration when applying DNS-01 to an existing Traefik configuration', function () {
    $server = dnsChallengeServer($this, ['dns_challenge_credentials' => ['CF_DNS_API_TOKEN' => 'pa$$word$HOME']]);
    $existing = <<<'YAML'
services:
  traefik:
    image: traefik:v3.7
    environment:
      - TZ=Asia/Seoul
    volumes:
      - /data/custom:/custom
    command:
      - '--providers.docker=true'
      - '--certificatesresolvers.dns.acme.dnschallenge.provider=oldprovider'
      - '--entrypoints.https.forwardedheaders.trustedips=10.0.0.0/8'
YAML;

    $traefik = Yaml::parse(applyDnsChallengeToProxyConfiguration($server, $existing))['services']['traefik'];

    expect($traefik['volumes'])->toBe(['/data/custom:/custom'])
        ->and($traefik['command'])->toContain('--entrypoints.https.forwardedheaders.trustedips=10.0.0.0/8')
        ->and($traefik['command'])->toContain('--certificatesresolvers.dns.acme.dnschallenge.provider=cloudflare')
        ->and($traefik['command'])->not->toContain('--certificatesresolvers.dns.acme.dnschallenge.provider=oldprovider')
        ->and($traefik['environment'])->toBe(['TZ' => 'Asia/Seoul', 'CF_DNS_API_TOKEN' => 'pa$$$$word$$HOME']);
});

it('removes only the credentials it added when the provider changes or DNS-01 is turned off', function () {
    $server = dnsChallengeServer($this);
    $configuration = applyDnsChallengeToProxyConfiguration($server, "services:\n  traefik:\n    environment:\n      TZ: Asia/Seoul\n    command: []\n");

    $server->settings->forceFill([
        'dns_challenge_provider' => 'route53',
        'dns_challenge_credentials' => ['AWS_ACCESS_KEY_ID' => 'AKIA', 'AWS_SECRET_ACCESS_KEY' => 'secret', 'AWS_REGION' => 'us-east-1'],
    ])->save();
    $configuration = applyDnsChallengeToProxyConfiguration($server->fresh(), $configuration);
    expect(Yaml::parse($configuration)['services']['traefik']['environment'])
        ->toBe(['TZ' => 'Asia/Seoul', 'AWS_ACCESS_KEY_ID' => 'AKIA', 'AWS_SECRET_ACCESS_KEY' => 'secret', 'AWS_REGION' => 'us-east-1']);

    $server->settings->forceFill(['is_dns_challenge_enabled' => false])->save();
    $traefik = Yaml::parse(applyDnsChallengeToProxyConfiguration($server->fresh(), $configuration))['services']['traefik'];

    expect($traefik['environment'])->toBe(['TZ' => 'Asia/Seoul'])
        ->and($traefik['command'])->toBe([])
        ->and($server->fresh()->proxy->get('dns_challenge_env_keys'))->toBeNull();
});

it('does not carry the dns resolver flags over as custom commands when the configuration is regenerated', function () {
    $server = dnsChallengeServer($this);
    $existing = "services:\n  traefik:\n    command:\n      - '--certificatesresolvers.dns.acme.dnschallenge=true'\n      - '--entrypoints.https.forwardedheaders.trustedips=10.0.0.0/8'\n";

    expect(extractCustomProxyCommands($server, $existing))->toBe(['--entrypoints.https.forwardedheaders.trustedips=10.0.0.0/8']);
});

it('stores the provider credentials encrypted and hides them from serialization', function () {
    $server = dnsChallengeServer($this);

    $raw = DB::table('server_settings')->where('server_id', $server->id)->value('dns_challenge_credentials');

    expect($raw)->not->toContain('cf-secret-token')
        ->and($server->settings->dns_challenge_credentials)->toBe(['CF_DNS_API_TOKEN' => 'cf-secret-token'])
        ->and($server->settings->toArray())->not->toHaveKey('dns_challenge_credentials')
        ->and(json_encode($server->settings))->not->toContain('cf-secret-token');
});

it('saves the configuration and restarts a running proxy when applied', function () {
    Queue::fake();
    $server = dnsChallengeServer($this);
    $server->proxy->set('status', 'running');
    $server->save();
    GetProxyConfiguration::partialMock()->shouldReceive('handle')->once()->andReturn("services:\n  traefik:\n    image: traefik:v3.7\n    command:\n      - '--providers.docker=true'\n");
    SaveProxyConfiguration::partialMock()->shouldReceive('handle')->once()->withArgs(
        fn (Server $saved, string $configuration): bool => str_contains($configuration, '--certificatesresolvers.dns.acme.dnschallenge.provider=cloudflare')
            && str_contains($configuration, 'CF_DNS_API_TOKEN: cf-secret-token')
            && str_contains($configuration, '--providers.docker=true')
    );

    expect(ApplyDnsChallenge::run($server->fresh()))->toBeTrue();
    Queue::assertPushed(RestartProxyJob::class);
});

it('does not start a proxy the user stopped', function () {
    Queue::fake();
    $server = dnsChallengeServer($this);
    $server->proxy->set('status', 'exited');
    $server->save();
    GetProxyConfiguration::partialMock()->shouldReceive('handle')->once()->andReturn("services:\n  traefik:\n    command: []\n");
    SaveProxyConfiguration::partialMock()->shouldReceive('handle')->once();

    expect(ApplyDnsChallenge::run($server->fresh()))->toBeFalse();
    Queue::assertNotPushed(RestartProxyJob::class);
});

it('refuses to apply DNS-01 to a Caddy proxy', function () {
    $server = dnsChallengeServer($this, [], 'CADDY');
    SaveProxyConfiguration::partialMock()->shouldReceive('handle')->never();

    expect(fn () => ApplyDnsChallenge::run($server))->toThrow(RuntimeException::class);
});
