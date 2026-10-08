<?php

use App\Livewire\Platform\Groups;
use App\Livewire\Platform\Logs;
use App\Livewire\Platform\Security;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\LogEntry;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\Support\TwoFactor;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    Cache::flush();

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->platformAdmin = TwoFactor::confirm(User::factory()->create());
    $this->rootTeam->members()->attach($this->platformAdmin->id, ['role' => 'admin']);

    $this->tenantTeam = Team::factory()->create(['name' => 'Tenant Team', 'show_boarding' => false]);
    $this->auditor = TwoFactor::confirm(User::factory()->create());
    $this->auditor->forceFill(['is_auditor' => true])->save();
    $this->tenantTeam->members()->attach($this->auditor->id, ['role' => 'member']);

    $this->tenant = User::factory()->create();
    $this->tenantTeam->members()->attach($this->tenant->id, ['role' => 'owner']);
});

function actAsAuditorTestUser(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

test('the viewAuditLogs gate allows platform admins and auditors only', function () {
    expect(Gate::forUser($this->platformAdmin)->allows('viewAuditLogs'))->toBeTrue()
        ->and(Gate::forUser($this->auditor)->allows('viewAuditLogs'))->toBeTrue()
        ->and(Gate::forUser($this->auditor)->allows('accessPlatformAdmin'))->toBeFalse()
        ->and(Gate::forUser($this->tenant)->allows('viewAuditLogs'))->toBeFalse();
});

test('auditors can open the logs page read-only', function () {
    actAsAuditorTestUser($this->auditor, $this->tenantTeam);

    $this->get('/platform/logs')
        ->assertOk()
        ->assertSee('Audit logs')
        ->assertSee('Log viewer')
        ->assertDontSee('Log collection')
        ->assertDontSee('Loki / Grafana')
        ->assertDontSee('Save collection settings');
});

test('auditors cannot open any other platform admin or settings page', function (string $path) {
    actAsAuditorTestUser($this->auditor, $this->tenantTeam);

    $this->get($path)->assertForbidden();
})->with(['/platform', '/platform/applications', '/platform/groups', '/platform/sources', '/platform/security', '/settings', '/settings/advanced']);

test('regular users still cannot open the logs page', function () {
    actAsAuditorTestUser($this->tenant, $this->tenantTeam);

    $this->get('/platform/logs')->assertForbidden();
    Livewire::test(Logs::class)->assertForbidden();
});

test('auditors see and export audit events of every team', function () {
    AuditEvent::factory()->create(['team_id' => $this->rootTeam->id, 'description' => 'Root team change']);
    AuditEvent::factory()->create(['team_id' => Team::factory()->create()->id, 'description' => 'Other team deploy']);
    LogEntry::factory()->create(['message' => 'Coolify queue warning']);
    actAsAuditorTestUser($this->auditor, $this->tenantTeam);

    $component = Livewire::test(Logs::class)
        ->assertSee('Root team change')
        ->assertSee('Other team deploy')
        ->set('search', 'deploy')
        ->assertDontSee('Root team change')
        ->set('search', '')
        ->set('source', 'system')
        ->assertSee('Coolify queue warning')
        ->call('export', 'ndjson')
        ->assertFileDownloaded();

    expect(base64_decode(data_get($component->effects, 'download.content')))->toContain('Coolify queue warning')
        ->and(AuditEvent::query()->where('event', 'platform.logs.exported')->where('actor_id', $this->auditor->id)->exists())->toBeTrue();
});

test('auditors cannot change any log setting', function (string $method, array $arguments) {
    instanceSettings()->update(['loki_url' => 'https://loki.example.com', 'loki_username' => 'grafana']);
    actAsAuditorTestUser($this->auditor, $this->tenantTeam);

    Livewire::test(Logs::class)
        ->set('isLogCollectionEnabled', true)
        ->set('isSystemLogCollectionEnabled', false)
        ->set('logRetentionDays', 999)
        ->set('isLokiShippingEnabled', true)
        ->set('lokiUrl', 'https://evil.example.com')
        ->call($method, ...$arguments)
        ->assertForbidden();

    $settings = InstanceSettings::query()->find(0);
    expect((bool) $settings->is_log_collection_enabled)->toBeFalse()
        ->and($settings->loki_url)->toBe('https://loki.example.com')
        ->and((bool) $settings->is_loki_shipping_enabled)->toBeFalse();
})->with([
    'collection' => ['saveCollection', []],
    'loki' => ['saveLoki', []],
    'loki test' => ['testLokiConnection', []],
    'loki password' => ['clearLokiPassword', []],
]);

test('auditors never receive the loki connection settings', function () {
    instanceSettings()->update(['loki_url' => 'https://loki.internal.example', 'loki_username' => 'grafana-user', 'loki_tenant_id' => 'tenant-a']);
    actAsAuditorTestUser($this->auditor, $this->tenantTeam);

    Livewire::test(Logs::class)
        ->assertSet('lokiUrl', null)
        ->assertSet('lokiUsername', null)
        ->assertSet('lokiTenantId', null)
        ->assertDontSee('loki.internal.example');
});

test('the navbar shows auditors an audit logs entry and keeps platform admin entries admin-only', function () {
    actAsAuditorTestUser($this->auditor, $this->tenantTeam);

    $this->get('/platform/logs')
        ->assertOk()
        ->assertSee('href="'.route('platform.logs').'"', false)
        ->assertDontSee('href="'.route('platform.dashboard').'"', false)
        ->assertDontSee('href="'.route('settings.index').'"', false);
});

test('platform admins keep the full logs page and the platform admin entry', function () {
    $this->platformAdmin->forceFill(['is_auditor' => true])->save();
    actAsAuditorTestUser($this->platformAdmin, $this->rootTeam);

    $this->get('/platform/logs')
        ->assertOk()
        ->assertSee('Log collection')
        ->assertSee('Loki / Grafana')
        ->assertSee('href="'.route('platform.dashboard').'"', false);
    expect(Gate::forUser($this->platformAdmin->fresh())->allows('accessPlatformAdmin'))->toBeTrue();
});

test('platform admins grant and revoke the auditor role after confirming their password', function (string $component) {
    Log::spy();
    actAsAuditorTestUser($this->platformAdmin, $this->rootTeam);

    Livewire::test($component)
        ->call('grantAuditor', $this->tenant->id, 'wrong-password')
        ->assertReturned('The provided password is incorrect.');
    expect((bool) $this->tenant->fresh()->is_auditor)->toBeFalse()
        ->and(AuditEvent::query()->where('event', 'platform.auditor.granted')->where('result', 'denied')->exists())->toBeTrue();

    Livewire::test($component)
        ->call('grantAuditor', $this->tenant->id, 'password')
        ->assertReturned(true)
        ->assertDispatched('success');
    expect((bool) $this->tenant->fresh()->is_auditor)->toBeTrue()
        ->and($this->tenant->fresh()->teams()->first()->pivot->role)->toBe('owner')
        ->and(AuditEvent::query()->where('event', 'platform.auditor.granted')->where('result', 'success')->exists())->toBeTrue();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ($context['type'] ?? null) === 'auditor_changed');

    Livewire::test($component)
        ->call('revokeAuditor', $this->tenant->id, 'password')
        ->assertReturned(true);
    expect((bool) $this->tenant->fresh()->is_auditor)->toBeFalse()
        ->and(AuditEvent::query()->where('event', 'platform.auditor.revoked')->where('result', 'success')->exists())->toBeTrue();
})->with(['security page' => [Security::class], 'groups page' => [Groups::class]]);

test('platform admins cannot change their own or the root user auditor role', function () {
    $root = User::find(0) ?? User::factory()->create(['id' => 0]);
    actAsAuditorTestUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Security::class)
        ->call('grantAuditor', $this->platformAdmin->id, 'password')
        ->assertReturned('You cannot change your own account here.')
        ->call('grantAuditor', $root->id, 'password')
        ->assertReturned('The root user account cannot be changed here.');

    expect((bool) $this->platformAdmin->fresh()->is_auditor)->toBeFalse()
        ->and(AuditEvent::query()->where('event', 'platform.auditor.granted')->where('result', 'denied')->count())->toBe(2);
});

test('auditors and regular users cannot change the auditor role', function (string $who) {
    $user = $who === 'auditor' ? $this->auditor : $this->tenant;
    actAsAuditorTestUser($user, $this->tenantTeam);

    Livewire::test(Security::class)->assertForbidden();
    expect((bool) $this->tenant->fresh()->is_auditor)->toBeFalse();
})->with(['auditor', 'regular user']);

test('the security page shows the auditor toggle and badge', function () {
    actAsAuditorTestUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Security::class)
        ->assertSee('Make auditor')
        ->assertSeeHtml('grantAuditor('.$this->tenant->id.')')
        ->assertSeeHtml('revokeAuditor('.$this->auditor->id.')')
        ->assertSee('Auditor');
});

test('auditors cannot delete or tamper with audit events', function () {
    $event = AuditEvent::factory()->create(['description' => 'Immutable record']);
    actAsAuditorTestUser($this->auditor, $this->tenantTeam);

    expect(fn () => $event->delete())->toThrow(LogicException::class)
        ->and(AuditEvent::query()->whereKey($event->id)->exists())->toBeTrue();
});
