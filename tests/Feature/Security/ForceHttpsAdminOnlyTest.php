<?php

use App\Livewire\Project\Application\Advanced;
use App\Livewire\Project\Application\Domains as ApplicationDomains;
use App\Livewire\Project\Service\Domains as ServiceDomains;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Support\ForceHttpsPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->withoutDefer();
    config()->set('app.maintenance.store', 'array');

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['id' => 0, 'is_dns_validation_enabled' => false]
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'ip' => '203.0.113.10']);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $this->destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
    ));
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://app.example.com',
        'redirect' => 'both',
        'build_pack' => 'nixpacks',
    ]);
    $this->application->settings()->update([
        'is_container_label_readonly_enabled' => true,
        'is_force_https_enabled' => true,
    ]);

    $this->service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => "services:\n  api:\n    image: node:alpine\n",
    ]);
    $this->serviceApp = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'service_id' => $this->service->id,
        'name' => 'api',
        'human_name' => 'API',
        'image' => 'node:alpine',
        'fqdn' => 'https://api.example.com',
    ]);
});

describe('policy', function () {
    it('lets anyone enable the redirect and only platform admins disable it', function () {
        $admin = makePlatformAdmin(User::factory()->create());

        expect(ForceHttpsPolicy::allowsChange(false, true, $this->user))->toBeTrue()
            ->and(ForceHttpsPolicy::allowsChange(false, false, $this->user))->toBeTrue()
            ->and(ForceHttpsPolicy::allowsChange(true, false, $this->user))->toBeFalse()
            ->and(ForceHttpsPolicy::allowsChange(true, false, null))->toBeFalse()
            ->and(ForceHttpsPolicy::allowsChange(true, false, $admin))->toBeTrue();
    });

    it('does not treat a member of the root team as a platform admin', function () {
        $member = makePlatformAdmin(User::factory()->create(), 'member');

        expect(ForceHttpsPolicy::canDisable($member))->toBeFalse();
    });
});

describe('application domains', function () {
    it('refuses to disable the redirect for a team owner who is not a platform admin', function () {
        Livewire::test(ApplicationDomains::class, ['application' => $this->application->fresh()])
            ->assertSet('canDisableForceHttps', false)
            ->assertSee('Only a platform administrator can disable the HTTP to HTTPS redirect.')
            ->set('isForceHttpsEnabled', false)
            ->call('updateForceHttps')
            ->assertDispatched('error')
            ->assertSet('isForceHttpsEnabled', true);

        expect($this->application->settings->fresh()->is_force_https_enabled)->toBeTrue()
            ->and(AuditEvent::query()->where('event', 'application.force_https.disable')->where('result', 'denied')->exists())->toBeTrue();
    });

    it('lets a team owner turn the redirect back on', function () {
        $this->application->settings()->update(['is_force_https_enabled' => false]);

        Livewire::test(ApplicationDomains::class, ['application' => $this->application->fresh()])
            ->assertDontSee('Only a platform administrator can disable the HTTP to HTTPS redirect.')
            ->set('isForceHttpsEnabled', true)
            ->call('updateForceHttps')
            ->assertDispatched('success');

        expect($this->application->settings->fresh()->is_force_https_enabled)->toBeTrue();
    });

    it('lets a platform admin disable the redirect', function () {
        makePlatformAdmin($this->user);

        Livewire::test(ApplicationDomains::class, ['application' => $this->application->fresh()])
            ->assertSet('canDisableForceHttps', true)
            ->assertDontSee('Only a platform administrator can disable the HTTP to HTTPS redirect.')
            ->set('isForceHttpsEnabled', false)
            ->call('updateForceHttps')
            ->assertDispatched('success');

        expect($this->application->settings->fresh()->is_force_https_enabled)->toBeFalse();
    });

    it('keeps the redirect when a non-admin sends it through the advanced settings form', function () {
        Livewire::test(Advanced::class, ['application' => $this->application->fresh()])
            ->set('isForceHttpsEnabled', false)
            ->call('submit');

        expect($this->application->settings->fresh()->is_force_https_enabled)->toBeTrue();
    });

    it('lets a platform admin change the redirect through the advanced settings form', function () {
        makePlatformAdmin($this->user);

        Livewire::test(Advanced::class, ['application' => $this->application->fresh()])
            ->set('isForceHttpsEnabled', false)
            ->call('submit');

        expect($this->application->settings->fresh()->is_force_https_enabled)->toBeFalse();
    });
});

describe('service domains', function () {
    it('refuses to disable the redirect for a team owner who is not a platform admin', function () {
        Livewire::test(ServiceDomains::class, ['service' => $this->service->fresh(['applications', 'server'])])
            ->assertSet('canDisableForceHttps', false)
            ->assertSee('Only a platform administrator can disable the HTTP to HTTPS redirect.')
            ->call('updateForceHttps', $this->serviceApp->id, false)
            ->assertDispatched('error')
            ->assertSet("forceHttpsRedirects.{$this->serviceApp->id}", true);

        expect($this->serviceApp->fresh()->is_force_https_enabled)->toBeTrue()
            ->and(AuditEvent::query()->where('event', 'service.force_https.disable')->where('result', 'denied')->exists())->toBeTrue();
    });

    it('lets a platform admin disable the redirect', function () {
        makePlatformAdmin($this->user);

        Livewire::test(ServiceDomains::class, ['service' => $this->service->fresh(['applications', 'server'])])
            ->assertSet('canDisableForceHttps', true)
            ->call('updateForceHttps', $this->serviceApp->id, false)
            ->assertDispatched('success');

        expect($this->serviceApp->fresh()->is_force_https_enabled)->toBeFalse();
    });

    it('rejects changes to the locked admin flag from the browser', function () {
        Livewire::test(ServiceDomains::class, ['service' => $this->service->fresh(['applications', 'server'])])
            ->set('canDisableForceHttps', true);
    })->throws(CannotUpdateLockedPropertyException::class);
});
