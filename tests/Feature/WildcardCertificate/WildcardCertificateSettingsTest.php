<?php

use App\Actions\Server\ApplyDnsChallenge;
use App\Livewire\Server\Proxy\WildcardCertificate;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Cache::flush();
    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create(['email' => 'owner@example.com']);
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    makePlatformAdmin($this->owner);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'proxy' => ['type' => 'TRAEFIK']]);
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
});

function wildcardSettingsComponent(Server $server)
{
    return Livewire::test(WildcardCertificate::class, ['server' => $server]);
}

it('saves a Cloudflare wildcard certificate without sending the token back to the browser', function () {
    $component = wildcardSettingsComponent($this->server)
        ->set('isEnabled', true)
        ->set('domain', 'https://*.Example.com/')
        ->set('provider', 'cloudflare')
        ->set('credentials.CF_DNS_API_TOKEN', 'cf-token')
        ->call('save')
        ->assertHasNoErrors();

    $settings = $this->server->fresh()->settings;
    expect($settings->is_dns_challenge_enabled)->toBeTrue()
        ->and($settings->dns_challenge_domain)->toBe('example.com')
        ->and($settings->dns_challenge_provider)->toBe('cloudflare')
        ->and($settings->dns_challenge_credentials)->toBe(['CF_DNS_API_TOKEN' => 'cf-token'])
        ->and($settings->dns_challenge_email)->toBe('owner@example.com')
        ->and($component->get('credentials.CF_DNS_API_TOKEN'))->toBeNull()
        ->and($component->get('storedCredentialKeys'))->toBe(['CF_DNS_API_TOKEN']);
    $component->assertDontSee('cf-token');
});

it('keeps a saved secret when its field is left empty', function () {
    $this->server->settings->forceFill([
        'is_dns_challenge_enabled' => true,
        'dns_challenge_domain' => 'example.com',
        'dns_challenge_provider' => 'cloudflare',
        'dns_challenge_credentials' => ['CF_DNS_API_TOKEN' => 'saved-token'],
    ])->save();

    wildcardSettingsComponent($this->server->fresh())
        ->set('domain', 'example.org')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->server->fresh()->settings->dns_challenge_credentials)->toBe(['CF_DNS_API_TOKEN' => 'saved-token'])
        ->and($this->server->fresh()->settings->dns_challenge_domain)->toBe('example.org');
});

it('requires the provider secrets again after switching providers', function () {
    $this->server->settings->forceFill([
        'is_dns_challenge_enabled' => true,
        'dns_challenge_domain' => 'example.com',
        'dns_challenge_provider' => 'cloudflare',
        'dns_challenge_credentials' => ['CF_DNS_API_TOKEN' => 'saved-token'],
    ])->save();

    wildcardSettingsComponent($this->server->fresh())
        ->set('provider', 'route53')
        ->call('save')
        ->assertHasErrors(['credentials.AWS_ACCESS_KEY_ID', 'credentials.AWS_SECRET_ACCESS_KEY']);

    expect($this->server->fresh()->settings->dns_challenge_provider)->toBe('cloudflare');
});

it('fills the default Route 53 region and RFC 2136 algorithm', function (string $provider, array $input, array $expected) {
    $component = wildcardSettingsComponent($this->server)
        ->set('isEnabled', true)
        ->set('domain', 'example.com')
        ->set('provider', $provider);
    foreach ($input as $key => $value) {
        $component->set("credentials.{$key}", $value);
    }
    $component->call('save')->assertHasNoErrors();

    expect($this->server->fresh()->settings->dns_challenge_credentials)->toBe($expected);
})->with([
    'route53' => ['route53', ['AWS_ACCESS_KEY_ID' => 'AKIA', 'AWS_SECRET_ACCESS_KEY' => 'secret'], ['AWS_ACCESS_KEY_ID' => 'AKIA', 'AWS_SECRET_ACCESS_KEY' => 'secret', 'AWS_REGION' => 'us-east-1']],
    'rfc2136' => ['rfc2136', ['RFC2136_NAMESERVER' => 'ns1.example.com:53', 'RFC2136_TSIG_KEY' => 'paded', 'RFC2136_TSIG_SECRET' => 's3cret=='], ['RFC2136_NAMESERVER' => 'ns1.example.com:53', 'RFC2136_TSIG_KEY' => 'paded', 'RFC2136_TSIG_SECRET' => 's3cret==', 'RFC2136_TSIG_ALGORITHM' => 'hmac-sha256.']],
]);

it('saves another lego provider from KEY=value lines', function () {
    wildcardSettingsComponent($this->server)
        ->set('isEnabled', true)
        ->set('domain', 'example.com')
        ->set('provider', 'other')
        ->set('otherProviderCode', 'gandiv5')
        ->set('otherCredentials', "# Gandi\nGANDIV5_PERSONAL_ACCESS_TOKEN=abc=123\n")
        ->call('save')
        ->assertHasNoErrors();

    $settings = $this->server->fresh()->settings;
    expect($settings->dns_challenge_provider)->toBe('gandiv5')
        ->and($settings->dns_challenge_credentials)->toBe(['GANDIV5_PERSONAL_ACCESS_TOKEN' => 'abc=123']);
});

it('rejects invalid input', function (string $property, mixed $value, string $errorKey) {
    wildcardSettingsComponent($this->server)
        ->set('isEnabled', true)
        ->set('domain', 'example.com')
        ->set('provider', 'other')
        ->set('otherProviderCode', 'gandiv5')
        ->set('otherCredentials', 'TOKEN=abc')
        ->set($property, $value)
        ->call('save')
        ->assertHasErrors([$errorKey]);

    expect($this->server->fresh()->settings->is_dns_challenge_enabled)->toBeFalse();
})->with([
    'provider code with symbols' => ['otherProviderCode', 'gandi;rm', 'otherProviderCode'],
    'lowercase env key' => ['otherCredentials', 'token=abc', 'otherCredentials'],
    'missing value' => ['otherCredentials', 'TOKEN=', 'otherCredentials'],
    'domain without a dot' => ['domain', 'localhost', 'domain'],
    'invalid email' => ['email', 'not-an-email', 'email'],
    'invalid resolvers' => ['resolvers', '1.1.1.1', 'resolvers'],
]);

it('lets the domain stay empty while DNS-01 is off', function () {
    wildcardSettingsComponent($this->server)
        ->set('isEnabled', false)
        ->set('domain', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->server->fresh()->settings->is_dns_challenge_enabled)->toBeFalse();
});

it('applies the settings and restarts the proxy', function () {
    ApplyDnsChallenge::partialMock()->shouldReceive('handle')->once()->andReturnTrue();

    wildcardSettingsComponent($this->server)
        ->set('isEnabled', true)
        ->set('domain', 'example.com')
        ->set('credentials.CF_DNS_API_TOKEN', 'cf-token')
        ->call('applyAndRestart')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($this->server->fresh()->settings->is_dns_challenge_enabled)->toBeTrue();
});

it('does not let a team member change the wildcard certificate', function () {
    ApplyDnsChallenge::partialMock()->shouldReceive('handle')->never();
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);

    wildcardSettingsComponent($this->server)
        ->set('isEnabled', true)
        ->set('domain', 'example.com')
        ->set('credentials.CF_DNS_API_TOKEN', 'cf-token')
        ->call('save')
        ->call('applyAndRestart');

    expect($this->server->fresh()->settings->is_dns_challenge_enabled)->toBeFalse();
});

it('does not let a team owner who is not a platform admin change the wildcard certificate', function () {
    ApplyDnsChallenge::partialMock()->shouldReceive('handle')->never();
    $teamOwner = User::factory()->create();
    $this->team->members()->attach($teamOwner->id, ['role' => 'owner']);
    $this->actingAs($teamOwner);

    wildcardSettingsComponent($this->server)
        ->set('isEnabled', true)
        ->set('domain', 'example.com')
        ->set('credentials.CF_DNS_API_TOKEN', 'cf-token')
        ->call('save')
        ->call('applyAndRestart');

    expect($this->server->fresh()->settings->is_dns_challenge_enabled)->toBeFalse();
});

it('does not open for a user of another team', function () {
    $outsider = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($outsider->id, ['role' => 'owner']);
    $this->actingAs($outsider);
    session(['currentTeam' => $otherTeam]);

    wildcardSettingsComponent($this->server)->assertForbidden();
});

it('shows a notice instead of the form for Caddy', function () {
    $this->server->proxy->set('type', 'CADDY');
    $this->server->save();

    wildcardSettingsComponent($this->server->fresh())
        ->assertSee('Traefik only')
        ->assertDontSee('Apply and restart proxy');
});
