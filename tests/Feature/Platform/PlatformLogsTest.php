<?php

use App\Livewire\Platform\Logs;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\LogEntry;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->platformAdmin = User::factory()->create();
    $this->rootTeam->members()->attach($this->platformAdmin->id, ['role' => 'admin']);

    $this->otherTeam = Team::factory()->create(['name' => 'Tenant Team']);
    $this->tenantOwner = User::factory()->create();
    $this->otherTeam->members()->attach($this->tenantOwner->id, ['role' => 'owner']);
});

function actAsLogsUser(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

test('guests are redirected to login from the logs page', function () {
    $this->get('/platform/logs')->assertRedirect('/login');
});

test('team owners outside the root team cannot open the logs page', function () {
    actAsLogsUser($this->tenantOwner, $this->otherTeam);

    $this->get('/platform/logs')->assertForbidden();
    Livewire::test(Logs::class)->assertForbidden();
});

test('root team members without admin role cannot open the logs component', function () {
    $member = User::factory()->create();
    $this->rootTeam->members()->attach($member->id, ['role' => 'member']);
    actAsLogsUser($member, $this->rootTeam);

    Livewire::test(Logs::class)->assertForbidden();
});

test('platform admins can open the logs page', function () {
    actAsLogsUser($this->platformAdmin, $this->rootTeam);

    $this->get('/platform/logs')->assertOk()->assertSee('Log collection')->assertSee('Loki / Grafana');
});

test('a user who loses platform admin rights cannot change log settings from an open page', function () {
    actAsLogsUser($this->platformAdmin, $this->rootTeam);
    $settingsForm = Livewire::test(Logs::class)->set('isLogCollectionEnabled', true);
    $exportForm = Livewire::test(Logs::class);

    $this->rootTeam->members()->updateExistingPivot($this->platformAdmin->id, ['role' => 'member']);
    $this->platformAdmin->unsetRelation('teams');

    $settingsForm->call('saveCollection')->assertForbidden();
    $exportForm->call('export', 'ndjson')->assertForbidden()->assertNoFileDownloaded();

    expect((bool) InstanceSettings::query()->find(0)->is_log_collection_enabled)->toBeFalse();
});

test('platform admins can turn log collection on and off', function () {
    actAsLogsUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Logs::class)
        ->assertSet('isLogCollectionEnabled', false)
        ->set('isLogCollectionEnabled', true)
        ->set('isContainerLogCollectionEnabled', true)
        ->set('isSystemLogCollectionEnabled', false)
        ->set('logRetentionDays', 14)
        ->call('saveCollection')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $settings = InstanceSettings::query()->find(0);
    expect($settings)
        ->is_log_collection_enabled->toBeTrue()
        ->is_container_log_collection_enabled->toBeTrue()
        ->is_system_log_collection_enabled->toBeFalse()
        ->log_retention_days->toBe(14)
        ->and($settings->isContainerLogCollectionActive())->toBeTrue()
        ->and($settings->isSystemLogCollectionActive())->toBeFalse();

    Livewire::test(Logs::class)
        ->set('isLogCollectionEnabled', false)
        ->call('saveCollection');

    $settings = InstanceSettings::query()->find(0);
    expect($settings->isContainerLogCollectionActive())->toBeFalse()
        ->and($settings->isAuditLogCollectionActive())->toBeFalse()
        ->and(AuditEvent::query()->where('event', 'platform.logs.collection_updated')->count())->toBe(2);
});

test('the retention period is validated', function (int $days) {
    actAsLogsUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Logs::class)
        ->set('logRetentionDays', $days)
        ->call('saveCollection')
        ->assertHasErrors('logRetentionDays');
})->with([0, 366]);

test('enabling loki shipping stores the encrypted password and the shipping start time', function () {
    $this->travelTo(now()->startOfMinute());
    actAsLogsUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Logs::class)
        ->set('isLokiShippingEnabled', true)
        ->set('lokiUrl', 'https://loki.example.com')
        ->set('lokiUsername', '123456')
        ->set('lokiPassword', 'glc_secret')
        ->set('lokiTenantId', 'tenant-a')
        ->call('saveLoki')
        ->assertHasNoErrors()
        ->assertSet('lokiPassword', null);

    $settings = InstanceSettings::query()->find(0);
    expect($settings)
        ->is_loki_shipping_enabled->toBeTrue()
        ->loki_url->toBe('https://loki.example.com')
        ->loki_password->toBe('glc_secret')
        ->loki_tenant_id->toBe('tenant-a')
        ->and($settings->loki_shipping_started_at->equalTo(now()))->toBeTrue()
        ->and(InstanceSettings::query()->toBase()->where('id', 0)->value('loki_password'))->not->toBe('glc_secret')
        ->and($settings->toArray())->not->toHaveKey('loki_password');

    // Saving again with a blank password keeps the stored one and the original start time.
    $this->travel(5)->minutes();
    Livewire::test(Logs::class)->set('lokiTenantId', 'tenant-b')->call('saveLoki')->assertHasNoErrors();

    $settings = InstanceSettings::query()->find(0);
    expect($settings->loki_password)->toBe('glc_secret')
        ->and($settings->loki_tenant_id)->toBe('tenant-b')
        ->and($settings->loki_shipping_started_at->equalTo(now()->subMinutes(5)))->toBeTrue();
});

test('the stored loki password is never sent to the browser', function () {
    InstanceSettings::query()->whereKey(0)->first()->update(['loki_username' => 'user', 'loki_password' => 'glc_secret']);
    actAsLogsUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Logs::class)
        ->assertSet('lokiPassword', null)
        ->assertDontSee('glc_secret')
        ->assertSee('Password stored');
});

test('loki settings are validated', function (array $values, string $field) {
    actAsLogsUser($this->platformAdmin, $this->rootTeam);

    $component = Livewire::test(Logs::class);
    foreach ($values as $property => $value) {
        $component->set($property, $value);
    }

    $component->call('saveLoki')->assertHasErrors($field);
})->with([
    'url is required when enabled' => [['isLokiShippingEnabled' => true, 'lokiUrl' => ''], 'lokiUrl'],
    'url must be http or https' => [['lokiUrl' => 'ftp://loki.example.com'], 'lokiUrl'],
    'tenant id cannot contain header characters' => [['lokiTenantId' => "tenant\r\nX-Evil: 1"], 'lokiTenantId'],
]);

test('the loki connection test pushes one line with the form credentials', function () {
    Http::fake(['loki.example.com/*' => Http::response('', 204)]);
    actAsLogsUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Logs::class)
        ->set('lokiUrl', 'https://loki.example.com/')
        ->set('lokiUsername', 'user')
        ->set('lokiPassword', 'pass')
        ->call('testLokiConnection')
        ->assertDispatched('success');

    Http::assertSent(fn ($request) => $request->url() === 'https://loki.example.com/loki/api/v1/push'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('user:pass'))
        && data_get($request->data(), 'streams.0.stream.source') === 'connection_test');
});

test('a failed loki connection test shows the loki response', function () {
    Http::fake(['loki.example.com/*' => Http::response('401 Unauthorized', 401)]);
    actAsLogsUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Logs::class)
        ->set('lokiUrl', 'https://loki.example.com')
        ->call('testLokiConnection')
        ->assertDispatched('error');
});

test('the viewer lists audit events of every team and collected log lines', function () {
    AuditEvent::factory()->create(['team_id' => $this->otherTeam->id, 'description' => 'Tenant deployed app']);
    LogEntry::factory()->create(['message' => 'Coolify queue warning']);
    LogEntry::factory()->container('tenant-web-1')->create(['message' => 'GET /health 200']);
    actAsLogsUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Logs::class)
        ->assertSee('Tenant deployed app')
        ->set('source', 'system')
        ->assertSee('Coolify queue warning')
        ->assertDontSee('GET /health 200')
        ->set('source', 'container')
        ->assertSee('GET /health 200')
        ->assertSee('tenant-web-1')
        ->set('search', 'nothing-matches')
        ->assertDontSee('GET /health 200');
});

test('an unknown source falls back to user activity', function () {
    actAsLogsUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Logs::class)
        ->set('source', 'secrets')
        ->assertSet('source', 'audit');
});

test('platform admins can export the filtered logs as ndjson', function () {
    $this->travelTo(now()->setDate(2026, 10, 2)->setTime(9, 30));
    LogEntry::factory()->container('web-1')->create(['message' => 'listening on :3000', 'level' => 'info']);
    LogEntry::factory()->container('web-1')->create(['message' => 'boom', 'level' => 'error']);
    actAsLogsUser($this->platformAdmin, $this->rootTeam);

    $component = Livewire::test(Logs::class)
        ->set('source', 'container')
        ->set('level', 'error')
        ->call('export', 'ndjson')
        ->assertFileDownloaded('coolify-container-logs-20261002-093000.ndjson');

    $lines = array_values(array_filter(explode("\n", base64_decode(data_get($component->effects, 'download.content')))));
    expect($lines)->toHaveCount(1)
        ->and(json_decode($lines[0], true))->toMatchArray(['source' => 'container', 'level' => 'error', 'message' => 'boom'])
        ->and(AuditEvent::query()->where('event', 'platform.logs.exported')->exists())->toBeTrue();
});

test('csv exports neutralize spreadsheet formulas', function () {
    LogEntry::factory()->create(['message' => '=HYPERLINK("https://evil.example")']);
    actAsLogsUser($this->platformAdmin, $this->rootTeam);

    $component = Livewire::test(Logs::class)
        ->set('source', 'system')
        ->call('export', 'csv');

    $csv = base64_decode(data_get($component->effects, 'download.content'));
    expect($csv)->toContain("'=HYPERLINK")
        ->and($csv)->not->toContain(',=HYPERLINK');
});

test('unknown export formats are rejected', function () {
    actAsLogsUser($this->platformAdmin, $this->rootTeam);

    Livewire::test(Logs::class)
        ->call('export', 'xlsx')
        ->assertDispatched('error')
        ->assertNoFileDownloaded();
});
