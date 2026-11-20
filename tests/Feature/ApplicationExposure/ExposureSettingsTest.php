<?php

use App\Livewire\Project\Application\General;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);
    InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://panel.example.org']);
    Once::flush();
    Cache::flush();

    $this->rootTeam = Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    Server::factory()->create(['id' => 0, 'team_id' => 0]);
    $this->platformAdmin = User::factory()->create();
    $this->rootTeam->members()->attach($this->platformAdmin->id, ['role' => 'admin']);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://app.example.org',
        'ports_exposes' => '3000',
        'redirect' => 'both',
        'build_pack' => 'nixpacks',
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'git_repository' => 'coollabsio/coolify',
        'git_branch' => 'main',
        'is_http_basic_auth_enabled' => false,
    ]);
    $this->application->settings->update(['is_container_label_readonly_enabled' => true]);
});

function exposureLabelsOf(Application $application): string
{
    $raw = (string) $application->custom_labels;
    $decoded = base64_decode($raw, true);

    return $decoded !== false && base64_encode($decoded) === $raw ? $decoded : $raw;
}

function signInForExposure(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
    Cache::flush();
}

it('lets platform admins save the internal network ranges', function () {
    signInForExposure($this->platformAdmin, $this->rootTeam);

    Livewire::test(SettingsIndex::class)
        ->set('internal_network_cidrs', "10.0.0.0/8, 192.168.0.0/16\n2001:db8::/32\n10.0.0.0/8")
        ->call('saveInternalNetworks')
        ->assertHasNoErrors();

    expect(InstanceSettings::find(0)->internal_network_cidrs)->toBe("10.0.0.0/8\n192.168.0.0/16\n2001:db8::/32");
});

it('rejects entries that are not IP addresses or CIDR ranges', function () {
    signInForExposure($this->platformAdmin, $this->rootTeam);

    Livewire::test(SettingsIndex::class)
        ->set('internal_network_cidrs', "10.0.0.0/8\nintranet.example.org\n10.0.0.0/40")
        ->call('saveInternalNetworks')
        ->assertHasErrors('internal_network_cidrs');

    expect(InstanceSettings::find(0)->internal_network_cidrs)->toBeNull();
});

it('does not let team owners change the internal network ranges', function () {
    signInForExposure($this->owner, $this->team);

    $this->get('/settings')->assertForbidden();
    Livewire::test(SettingsIndex::class)->assertRedirect(route('dashboard'));

    // A component opened by a platform admin still checks the caller on save.
    signInForExposure($this->platformAdmin, $this->rootTeam);
    $component = Livewire::test(SettingsIndex::class);
    signInForExposure($this->owner, $this->team);
    $component->set('internal_network_cidrs', '0.0.0.0/0')
        ->call('saveInternalNetworks')
        ->assertForbidden();

    expect(InstanceSettings::find(0)->internal_network_cidrs)->toBeNull();
});

it('saves the exposure mode and regenerates the managed proxy labels', function () {
    signInForExposure($this->owner, $this->team);

    Livewire::test(General::class, ['application' => $this->application])
        ->assertSet('exposureMode', 'public')
        ->set('exposureMode', 'login')
        ->call('instantSave')
        ->assertHasNoErrors();

    $application = $this->application->fresh();
    expect($application->exposure_mode)->toBe('login')
        ->and(exposureLabelsOf($application))
        ->toContain("signed-in-only-{$application->uuid}.forwardauth.address=https://panel.example.org/proxy-auth/verify");
});

it('warns when an internal network only application has no ranges to allow', function () {
    signInForExposure($this->owner, $this->team);
    $this->application->update(['exposure_mode' => 'internal']);

    Livewire::test(General::class, ['application' => $this->application])
        ->assertSee('No internal network ranges are set');
});

it('rejects unknown exposure modes', function () {
    signInForExposure($this->owner, $this->team);

    Livewire::test(General::class, ['application' => $this->application])
        ->set('exposureMode', 'everyone')
        ->call('instantSave');

    expect($this->application->fresh()->exposure_mode)->toBe('public');
});

it('does not let other teams change the exposure mode', function () {
    $outsider = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($outsider->id, ['role' => 'owner']);
    signInForExposure($outsider, $otherTeam);

    Livewire::test(General::class, ['application' => $this->application])
        ->set('exposureMode', 'public')
        ->call('instantSave');

    expect($this->application->fresh()->exposure_mode)->toBe('public');

    $this->application->update(['exposure_mode' => 'private']);
    Livewire::test(General::class, ['application' => $this->application])
        ->set('exposureMode', 'public')
        ->call('instantSave');

    expect($this->application->fresh()->exposure_mode)->toBe('private');
});

it('updates the exposure mode through the API and regenerates the managed proxy labels', function () {
    session(['currentTeam' => $this->team]);
    $token = $this->owner->createToken('exposure', ['*'])->plainTextToken;

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->patchJson("/api/v1/applications/{$this->application->uuid}", ['exposure_mode' => 'internal'])
        ->assertOk();

    $application = $this->application->fresh();
    expect($application->exposure_mode)->toBe('internal')
        ->and(exposureLabelsOf($application))->toContain('ipallowlist.sourcerange=255.255.255.255/32');

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->patchJson("/api/v1/applications/{$this->application->uuid}", ['exposure_mode' => 'everyone'])
        ->assertUnprocessable();

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson("/api/v1/applications/{$this->application->uuid}")
        ->assertOk()
        ->assertJsonPath('exposure_mode', 'internal');
});
