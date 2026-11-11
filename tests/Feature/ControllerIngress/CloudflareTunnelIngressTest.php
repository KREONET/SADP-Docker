<?php

use App\Actions\Proxy\SaveProxyConfiguration;
use App\Actions\Server\ConfigureCloudflareTunnelIngress;
use App\Jobs\RestartProxyJob;
use App\Livewire\Project\Application\Domains;
use App\Livewire\Server\Proxy\CloudflareTunnelIngress;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

const TUNNEL_ID = '0b1f6c2e-3d4a-4b5c-8d9e-0f1a2b3c4d5e';

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Cache::flush();
    Once::flush();
    $this->team = Team::factory()->create();
});

function tunnelToken(): string
{
    return base64_encode(json_encode(['a' => 'account-tag', 't' => TUNNEL_ID, 's' => base64_encode('secret')]));
}

function tunnelServer(Team $team, bool $tunnel = true, string $proxy = 'TRAEFIK', array $attributes = [], array $settings = []): Server
{
    $privateKey = PrivateKey::query()->where('team_id', $team->id)->first() ?? PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(array_merge(['team_id' => $team->id, 'private_key_id' => $privateKey->id, 'proxy' => ['type' => $proxy]], $attributes));
    $server->settings->forceFill(array_merge([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
        'generate_exact_labels' => true,
        'is_cloudflare_tunnel_ingress_enabled' => $tunnel,
        'cloudflare_tunnel_ingress_token' => $tunnel ? tunnelToken() : null,
    ], $settings))->save();
    Server::flushIdentityMap();

    return $server->fresh();
}

function tunnelApplication(Server $server, string $fqdn = 'https://app.example.com'): Application
{
    $project = Project::factory()->create(['team_id' => $server->team_id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();

    return Application::factory()->createOne([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'fqdn' => $fqdn,
        'ports_exposes' => '3000',
        'redirect' => 'both',
        'is_http_basic_auth_enabled' => false,
    ])->fresh();
}

describe('labels behind a Cloudflare Tunnel', function () {
    it('serves Traefik routers on the HTTP entrypoint and keeps their access middlewares', function () {
        $server = tunnelServer($this->team);
        $labels = fqdnLabelsForTraefik('abc', collect(['https://app.example.com']), is_force_https_enabled: true, onlyPort: 3000, access: [
            'mode' => 'internal',
            'allowed_ranges' => ['10.0.0.0/8'],
            'traefik_ip_middleware' => 'ipallowlist',
        ]);

        $result = applyCloudflareTunnelLabels($labels, $server, true)->values()->all();

        expect($result)->toContain('traefik.http.routers.https-0-abc.entryPoints=http')
            ->and($result)->toContain('traefik.http.routers.https-0-abc.middlewares=internal-only-abc,gzip')
            ->and($result)->toContain('traefik.http.services.https-0-abc.loadbalancer.server.port=3000')
            ->and(collect($result)->filter(fn ($label) => str_starts_with($label, 'traefik.http.routers.http-0-abc.'))->all())->toBe([])
            ->and(collect($result)->filter(fn ($label) => str_starts_with($label, 'traefik.http.services.http-0-abc.'))->all())->toBe([])
            ->and(collect($result)->filter(fn ($label) => str_contains($label, '.tls'))->all())->toBe([]);
    });

    it('serves Caddy sites over plain HTTP', function () {
        $server = tunnelServer($this->team, proxy: 'CADDY');

        expect(applyCloudflareTunnelLabels(collect(['caddy_0=http://a.example.com, https://a.example.com', 'caddy_1=https://b.example.com']), $server, true)->all())
            ->toBe(['caddy_0=http://a.example.com', 'caddy_1=http://b.example.com']);
    });

    it('leaves labels unchanged without a tunnel and is idempotent with one', function () {
        $labels = fqdnLabelsForTraefik('abc', collect(['https://app.example.com']), is_force_https_enabled: true, onlyPort: 3000);
        $tunnel = tunnelServer($this->team);

        expect(applyCloudflareTunnelLabels($labels, tunnelServer($this->team, tunnel: false), true)->all())->toBe($labels->all());

        $once = applyCloudflareTunnelLabels($labels, $tunnel, true);
        expect(applyCloudflareTunnelLabels($once, $tunnel, true)->all())->toBe($once->all());
    });

    it('keeps direct HTTPS labels for resources that did not choose the tunnel', function () {
        $labels = fqdnLabelsForTraefik('abc', collect(['https://app.example.com']), is_force_https_enabled: true, onlyPort: 3000);

        expect(applyCloudflareTunnelLabels($labels, tunnelServer($this->team))->all())->toBe($labels->all());
    });

    it('serves only the applications set to the tunnel over HTTP', function () {
        $server = tunnelServer($this->team);
        $direct = tunnelApplication($server, 'https://direct.example.com');
        $tunneled = tunnelApplication($server, 'https://tunnel.example.com');
        $tunneled->settings->forceFill(['is_cloudflare_tunnel_enabled' => true])->save();

        $directLabels = collect(generateLabelsApplication($direct->fresh()));
        $tunnelLabels = collect(generateLabelsApplication($tunneled->fresh()));

        expect($direct->fresh()->usesCloudflareTunnel())->toBeFalse()
            ->and($tunneled->fresh()->usesCloudflareTunnel())->toBeTrue()
            ->and($directLabels->filter(fn ($label) => str_contains($label, 'entryPoints=https'))->isNotEmpty())->toBeTrue()
            ->and($tunnelLabels->filter(fn ($label) => str_contains($label, 'entryPoints=https'))->all())->toBe([]);
    });

    it('serves the dashboard directly while the tunnel runs', function () {
        $server = tunnelServer($this->team);

        expect($server->servesTrafficThroughCloudflareTunnel())->toBeTrue();
        $source = file_get_contents(app_path('Models/Server.php'));
        expect($source)->not->toContain("servesTrafficThroughCloudflareTunnel() ? 'http'");
    });

    it('falls back to direct when an admin turns Cloudflare Tunnel support off', function () {
        $server = tunnelServer($this->team);
        $application = tunnelApplication($server);
        $application->settings->forceFill(['is_cloudflare_tunnel_enabled' => true])->save();

        InstanceSettings::query()->whereKey(0)->update(['is_cloudflare_tunnel_allowed' => false]);
        Once::flush();

        expect($server->fresh()->servesTrafficThroughCloudflareTunnel())->toBeFalse()
            ->and($application->fresh()->usesCloudflareTunnel())->toBeFalse()
            ->and(ConfigureCloudflareTunnelIngress::blockers($server->fresh()))->toContain(__('An instance admin turned off Cloudflare Tunnel support.'));
    });

    it('generates HTTP-only labels for an application on a worker behind the controller tunnel', function () {
        tunnelServer($this->team, attributes: ['id' => 0, 'ip' => 'host.docker.internal']);
        $worker = tunnelServer($this->team, tunnel: false, settings: ['is_routed_through_controller' => true, 'internal_ip' => '10.0.0.12']);
        $application = tunnelApplication($worker);
        $application->settings->forceFill(['is_cloudflare_tunnel_enabled' => true])->save();

        $labels = collect(generateLabelsApplication($application->fresh()));

        expect($labels->filter(fn ($label) => str_contains($label, 'entryPoints=https'))->all())->toBe([])
            ->and($labels->filter(fn ($label) => str_contains($label, 'entryPoints=http'))->isNotEmpty())->toBeTrue();
    });
});

describe('proxy configuration for the tunnel', function () {
    it('trusts forwarded headers from private ranges only while the tunnel is on', function () {
        $server = tunnelServer($this->team);
        SaveProxyConfiguration::partialMock()->shouldReceive('handle');

        $enabled = Yaml::parse(generateDefaultProxyConfiguration($server))['services']['traefik']['command'];
        $server->settings->forceFill(['is_cloudflare_tunnel_ingress_enabled' => false])->save();
        $disabled = applyCloudflareTunnelToProxyConfigArray($server->fresh(), ['services' => ['traefik' => ['command' => $enabled]]])['services']['traefik']['command'];

        expect($enabled)->toContain(CLOUDFLARE_TUNNEL_TRAEFIK_TRUSTED_IPS_COMMAND)
            ->and(array_count_values($enabled)[CLOUDFLARE_TUNNEL_TRAEFIK_TRUSTED_IPS_COMMAND])->toBe(1)
            ->and($disabled)->not->toContain(CLOUDFLARE_TUNNEL_TRAEFIK_TRUSTED_IPS_COMMAND);
    });

    it('adds the trusted proxies global option to Caddy', function () {
        $server = tunnelServer($this->team, proxy: 'CADDY');

        $config = applyCloudflareTunnelToProxyConfigArray($server, ['services' => ['caddy' => ['labels' => ['coolify.managed=true']]]]);

        expect($config['services']['caddy']['labels'])->toBe(['coolify.managed=true', CLOUDFLARE_TUNNEL_CADDY_TRUSTED_PROXIES_LABEL]);
    });

    it('reads the tunnel ID from a token and rejects other values', function () {
        expect(ConfigureCloudflareTunnelIngress::tunnelIdFromToken(tunnelToken()))->toBe(TUNNEL_ID)
            ->and(ConfigureCloudflareTunnelIngress::tunnelIdFromToken('not a token'))->toBeNull()
            ->and(ConfigureCloudflareTunnelIngress::tunnelIdFromToken(base64_encode('{"t":"x"}')))->toBeNull();
    });

    it('is not offered on a worker routed through the controller', function () {
        $worker = tunnelServer($this->team, tunnel: false, settings: ['is_routed_through_controller' => true]);

        expect(ConfigureCloudflareTunnelIngress::blockers($worker))->not->toBe([]);
    });
});

describe('tunnel settings', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
        makePlatformAdmin($this->owner);
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);
        Process::fake();
        Queue::fake();
    });

    it('starts cloudflared with the token and keeps the token out of the browser', function () {
        $server = tunnelServer($this->team, tunnel: false);
        $server->proxy->set('status', 'running');
        $server->save();

        $component = Livewire::test(CloudflareTunnelIngress::class, ['server' => $server->fresh()])
            ->set('isEnabled', true)
            ->set('token', tunnelToken())
            ->call('applyAndRestart')
            ->assertHasNoErrors();

        $settings = $server->fresh()->settings;
        expect($settings->is_cloudflare_tunnel_ingress_enabled)->toBeTrue()
            ->and($settings->cloudflare_tunnel_ingress_token)->toBe(tunnelToken())
            ->and($component->get('token'))->toBeNull()
            ->and($component->get('tunnelId'))->toBe(TUNNEL_ID);
        $component->assertDontSee(tunnelToken());
        Process::assertRan(fn ($process) => str_contains($process->command, 'coolify-cloudflared-ingress') && str_contains($process->command, 'up -d'));
        Queue::assertPushed(RestartProxyJob::class);
    });

    it('starts cloudflared when a token is pasted while the tunnel is off', function () {
        $server = tunnelServer($this->team, tunnel: false);
        $server->proxy->set('status', 'running');
        $server->save();

        Livewire::test(CloudflareTunnelIngress::class, ['server' => $server->fresh()])
            ->set('token', tunnelToken())
            ->call('applyAndRestart')
            ->assertHasNoErrors()
            ->assertSet('isEnabled', true);

        expect($server->fresh()->settings->is_cloudflare_tunnel_ingress_enabled)->toBeTrue();
        Process::assertRan(fn ($process) => str_contains($process->command, 'coolify-cloudflared-ingress') && str_contains($process->command, 'up -d'));
    });

    it('warns when a token is saved but the tunnel is off', function () {
        $server = tunnelServer($this->team, tunnel: false, settings: ['cloudflare_tunnel_ingress_token' => tunnelToken()]);

        Livewire::test(CloudflareTunnelIngress::class, ['server' => $server])
            ->assertSee(__('The tunnel is off'))
            ->assertSeeHtml('wire:model.live=isEnabled');
    });

    it('keeps the tunnel off when it was on and is turned off with a new token', function () {
        $server = tunnelServer($this->team);

        Livewire::test(CloudflareTunnelIngress::class, ['server' => $server])
            ->set('isEnabled', false)
            ->set('token', tunnelToken())
            ->call('applyAndRestart')
            ->assertHasNoErrors();

        expect($server->fresh()->settings->is_cloudflare_tunnel_ingress_enabled)->toBeFalse();
    });

    it('lets an admin stop cloudflared everywhere and allow it again', function () {
        $server = tunnelServer($this->team);
        InstanceSettings::query()->whereKey(0)->update(['is_cloudflare_tunnel_allowed' => false]);
        Once::flush();

        expect(ConfigureCloudflareTunnelIngress::disableEverywhere())->toBe([]);
        expect($server->fresh()->settings->is_cloudflare_tunnel_ingress_enabled)->toBeFalse()
            ->and($server->fresh()->settings->cloudflare_tunnel_ingress_token)->toBe(tunnelToken());
        Process::assertRan(fn ($process) => str_contains($process->command, 'docker rm -f coolify-cloudflared-ingress'));

        Livewire::test(CloudflareTunnelIngress::class, ['server' => $server->fresh()])
            ->set('isEnabled', true)
            ->call('applyAndRestart')
            ->assertHasErrors(['isEnabled']);
        expect($server->fresh()->settings->is_cloudflare_tunnel_ingress_enabled)->toBeFalse();
        Process::assertNotRan(fn ($process) => str_contains($process->command, 'up -d'));

        InstanceSettings::query()->whereKey(0)->update(['is_cloudflare_tunnel_allowed' => true]);
        Once::flush();
        expect(ConfigureCloudflareTunnelIngress::blockers($server->fresh()))->toBe([]);
    });

    it('chooses the traffic entry per application in the domain settings', function () {
        $server = tunnelServer($this->team);
        $application = tunnelApplication($server);

        Livewire::test(Domains::class, ['application' => $application])
            ->assertSet('cloudflareTunnelAvailable', true)
            ->set('isCloudflareTunnelEnabled', true)
            ->call('updateCloudflareTunnel')
            ->assertSet('isCloudflareTunnelEnabled', true);

        expect($application->fresh()->settings->is_cloudflare_tunnel_enabled)->toBeTrue()
            ->and($application->fresh()->usesCloudflareTunnel())->toBeTrue();
    });

    it('refuses the tunnel entry when the server has no tunnel', function () {
        $server = tunnelServer($this->team, tunnel: false);
        $application = tunnelApplication($server);

        Livewire::test(Domains::class, ['application' => $application])
            ->set('isCloudflareTunnelEnabled', true)
            ->call('updateCloudflareTunnel')
            ->assertSet('isCloudflareTunnelEnabled', false);

        expect($application->fresh()->settings->is_cloudflare_tunnel_enabled)->toBeFalse();
    });

    it('rejects a value that is not a tunnel token', function () {
        $server = tunnelServer($this->team, tunnel: false);

        Livewire::test(CloudflareTunnelIngress::class, ['server' => $server])
            ->set('isEnabled', true)
            ->set('token', 'cloudflared service install abc')
            ->call('applyAndRestart')
            ->assertHasErrors(['token']);

        expect($server->fresh()->settings->is_cloudflare_tunnel_ingress_enabled)->toBeFalse();
    });

    it('removes cloudflared when the tunnel is turned off', function () {
        $server = tunnelServer($this->team);

        Livewire::test(CloudflareTunnelIngress::class, ['server' => $server])
            ->set('isEnabled', false)
            ->call('applyAndRestart')
            ->assertHasNoErrors();

        expect($server->fresh()->settings->is_cloudflare_tunnel_ingress_enabled)->toBeFalse();
        Process::assertRan(fn ($process) => str_contains($process->command, 'docker rm -f coolify-cloudflared-ingress'));
    });
});
