<?php

use App\Livewire\Settings\Advanced;
use App\Livewire\Settings\Index;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Rules\ValidIpOrCidrList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $this->rootTeam->id]);
    $this->settings = InstanceSettings::forceCreate([
        'id' => 0,
        'is_registration_enabled' => false,
        'is_api_enabled' => false,
        'is_mcp_server_enabled' => false,
        'do_not_track' => true,
    ]);
    Once::flush();

    $this->admin = User::factory()->create();
    $this->rootTeam->members()->attach($this->admin->id, ['role' => 'admin']);

    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->rootTeam->id]]);
});

function settingsUpdatedEvents(): Collection
{
    return AuditEvent::query()->where('event', 'instance.settings.updated')->get();
}

test('new security and outbound controls are shown and the two-step bypass is gone', function () {
    Livewire::test(Advanced::class)
        ->assertSee('Send anonymous usage data')
        ->assertSee('Error reports')
        ->assertSee('Release notes')
        ->assertSee('Feedback forwarding')
        ->assertSee('Allowed administrator IPs')
        ->assertSee('Audit log retention (days)')
        ->assertSee('off by default so the instance works on closed networks', false)
        ->assertDontSee('Destructive action confirmation')
        ->assertDontSee('Skip two-step confirmation');

    expect(property_exists(Advanced::class, 'disable_two_step_confirmation'))->toBeFalse();
});

test('outbound features stay off by default', function () {
    Livewire::test(Advanced::class)
        ->assertSet('do_not_track', true)
        ->assertSet('is_error_reporting_enabled', false)
        ->assertSet('is_changelog_enabled', false)
        ->assertSet('is_feedback_forwarding_enabled', false)
        ->assertSet('audit_log_retention_days', 365);
});

test('security-relevant changes are not saved without password confirmation', function () {
    Livewire::test(Advanced::class)
        ->set('is_api_enabled', true)
        ->set('is_error_reporting_enabled', true)
        ->call('submit')
        ->assertDispatched('confirm-security-settings')
        ->assertNotDispatched('success')
        ->assertSee('Security settings not saved yet');

    $fresh = $this->settings->fresh();
    expect((bool) $fresh->is_api_enabled)->toBeFalse()
        ->and($fresh->is_error_reporting_enabled)->toBeFalse()
        ->and(settingsUpdatedEvents())->toHaveCount(0);
});

test('a wrong password refuses the security change and is audited as denied', function () {
    $component = Livewire::test(Advanced::class)
        ->set('is_registration_enabled', true)
        ->call('submit', 'wrong-password')
        ->assertHasErrors('password')
        ->assertNotDispatched('success');

    expect($component->effects['returns'][0] ?? null)->toBe('The provided password is incorrect.')
        ->and($this->settings->fresh()->is_registration_enabled)->toBeFalse();

    $event = settingsUpdatedEvents()->sole();
    expect($event->metadata['result'])->toBe('denied')
        ->and($event->metadata['fields'])->toBe(['is_registration_enabled']);
});

test('the correct password saves security changes, audits old and new values and raises an alert', function () {
    Log::spy();

    Livewire::test(Advanced::class)
        ->set('is_api_enabled', true)
        ->set('allowed_ips', '10.0.0.0/8')
        ->set('do_not_track', false)
        ->set('is_changelog_enabled', true)
        ->call('submit', 'password')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $fresh = $this->settings->fresh();
    expect((bool) $fresh->is_api_enabled)->toBeTrue()
        ->and($fresh->allowed_ips)->toBe('10.0.0.0/8')
        ->and($fresh->do_not_track)->toBeFalse()
        ->and($fresh->is_changelog_enabled)->toBeTrue();

    $event = settingsUpdatedEvents()->sole();
    expect($event->metadata['result'])->toBe('success')
        ->and($event->metadata['fields'])->toEqualCanonicalizing(['is_api_enabled', 'allowed_ips', 'do_not_track', 'is_changelog_enabled'])
        ->and($event->metadata['changes']['is_api_enabled'])->toBe(['old' => false, 'new' => true])
        ->and($event->metadata['changes']['allowed_ips'])->toBe(['old' => null, 'new' => '10.0.0.0/8']);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'Instance security settings changed')
            && $context['type'] === 'instance_security_setting_changed'
            && in_array('is_api_enabled', $context['fields'], true)
            && in_array('allowed_ips', $context['fields'], true)
            && ! in_array('do_not_track', $context['fields'], true))
        ->once();
});

test('instantSave never writes password-protected settings', function () {
    Livewire::test(Advanced::class)
        ->set('is_registration_enabled', true)
        ->set('is_mcp_server_enabled', true)
        ->set('is_wire_navigate_enabled', false)
        ->call('instantSave')
        ->assertDispatched('success');

    $fresh = $this->settings->fresh();
    expect($fresh->is_registration_enabled)->toBeFalse()
        ->and($fresh->is_mcp_server_enabled)->toBeFalse()
        ->and($fresh->is_wire_navigate_enabled)->toBeFalse();

    $event = settingsUpdatedEvents()->sole();
    expect($event->metadata['fields'])->toBe(['is_wire_navigate_enabled']);
});

test('non-protected changes save without a password and are audited once', function () {
    Livewire::test(Advanced::class)
        ->set('audit_log_retention_days', 730)
        ->set('custom_dns_servers', '1.1.1.1')
        ->call('submit')
        ->assertNotDispatched('confirm-security-settings')
        ->assertDispatched('success');

    expect($this->settings->fresh()->audit_log_retention_days)->toBe(730);

    $event = settingsUpdatedEvents()->sole();
    expect($event->metadata['changes']['audit_log_retention_days'])->toBe(['old' => 365, 'new' => 730]);
});

test('audit log retention cannot go below the configured minimum', function () {
    Livewire::test(Advanced::class)
        ->set('audit_log_retention_days', 30)
        ->call('submit')
        ->assertHasErrors(['audit_log_retention_days' => 'min']);

    expect($this->settings->fresh()->audit_log_retention_days)->toBe(365);
});

test('administrator IP allowlist accepts addresses and ranges separated by lines or commas', function () {
    Livewire::test(Advanced::class)
        ->set('admin_allowed_ips', "127.0.0.1\n10.0.0.0/8, 2001:db8::/32")
        ->call('submit', 'password')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($this->settings->fresh()->admin_allowed_ips)->toBe("127.0.0.1\n10.0.0.0/8\n2001:db8::/32");

    $event = settingsUpdatedEvents()->sole();
    expect($event->metadata['fields'])->toBe(['admin_allowed_ips']);
});

test('administrator IP allowlist rejects invalid entries', function () {
    Livewire::test(Advanced::class)
        ->set('admin_allowed_ips', "127.0.0.1\nnot-an-ip\n10.0.0.0/33")
        ->call('submit', 'password')
        ->assertHasErrors('admin_allowed_ips');

    expect($this->settings->fresh()->admin_allowed_ips)->toBeNull();
});

test('an administrator IP allowlist that excludes the current request is refused', function () {
    Livewire::test(Advanced::class)
        ->assertSee('Your current IP address is 127.0.0.1')
        ->set('admin_allowed_ips', '203.0.113.0/24')
        ->call('submit', 'password')
        ->assertHasErrors('admin_allowed_ips')
        ->assertNotDispatched('success');

    expect($this->settings->fresh()->admin_allowed_ips)->toBeNull()
        ->and(settingsUpdatedEvents())->toHaveCount(0);
});

test('an allowlist entry of 0.0.0.0 does not count as allowing the current request', function () {
    Livewire::test(Advanced::class)
        ->set('admin_allowed_ips', '0.0.0.0')
        ->call('submit', 'password')
        ->assertHasErrors('admin_allowed_ips');

    expect($this->settings->fresh()->admin_allowed_ips)->toBeNull();
});

test('secret values are never written to the audit log', function () {
    config()->set('constants.coolify.self_hosted', false);

    Livewire::test(Advanced::class)
        ->set('domain_connect_private_key', "-----BEGIN PRIVATE KEY-----\nsecret-material\n-----END PRIVATE KEY-----")
        ->call('submit');

    $event = settingsUpdatedEvents()->sole();
    expect($event->metadata['fields'])->toContain('domain_connect_private_key')
        ->and($event->metadata['changes'])->not->toHaveKey('domain_connect_private_key')
        ->and(json_encode($event->metadata))->not->toContain('secret-material');
});

test('members of the root team cannot change instance settings', function () {
    $member = User::factory()->create();
    $this->rootTeam->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(Advanced::class)->assertRedirect(route('dashboard'));
    Livewire::test(Index::class)->assertRedirect(route('dashboard'));
});

test('general settings saves are audited with old and new values', function () {
    Livewire::test(Index::class)
        ->set('instance_name', 'Closed network')
        ->set('instance_timezone', 'Asia/Seoul')
        ->call('submit')
        ->assertHasNoErrors();

    $event = settingsUpdatedEvents()->sole();
    expect($event->metadata['fields'])->toEqualCanonicalizing(['instance_name', 'instance_timezone'])
        ->and($event->metadata['changes']['instance_name'])->toBe(['old' => null, 'new' => 'Closed network']);
});

test('masked public addresses are audited by name only', function () {
    Livewire::test(Index::class)
        ->set('public_ipv4', '198.51.100.7')
        ->call('submit')
        ->assertHasNoErrors();

    $event = settingsUpdatedEvents()->sole();
    expect($event->metadata['fields'])->toBe(['public_ipv4'])
        ->and($event->metadata['changes'])->toBe([])
        ->and(json_encode($event->metadata))->not->toContain('198.51.100.7');
});

test('internal network, branding and default language saves are audited', function () {
    Livewire::test(Index::class)
        ->set('internal_network_cidrs', '10.0.0.0/8')
        ->call('saveInternalNetworks')
        ->set('brand_name', 'SADP-Docker-Security')
        ->call('saveBranding');

    $fields = settingsUpdatedEvents()->pluck('metadata.fields')->all();
    expect($fields)->toBe([['internal_network_cidrs'], ['brand_name']]);
});

test('the two-step confirmation migration forces the bypass off', function () {
    $this->settings->forceFill(['disable_two_step_confirmation' => true])->save();

    $migration = require database_path('migrations/2026_10_08_020947_force_two_step_confirmation_on_instance_settings.php');
    $migration->up();

    expect((bool) $this->settings->fresh()->disable_two_step_confirmation)->toBeFalse();
});

test('the IP or CIDR list rule validates every entry', function (?string $value, bool $passes) {
    $validator = Validator::make(['ips' => $value], ['ips' => [new ValidIpOrCidrList]]);

    expect($validator->passes())->toBe($passes);
})->with([
    'empty' => [null, true],
    'single ipv4' => ['192.168.1.10', true],
    'ipv6 cidr' => ['2001:db8::/48', true],
    'mixed separators' => ["10.0.0.0/8,\n172.16.0.0/12 192.168.0.1", true],
    'bad prefix' => ['10.0.0.0/40', false],
    'hostname' => ['admin.example.com', false],
    'wildcard prefix is not special' => ['0.0.0.0foo', false],
]);
