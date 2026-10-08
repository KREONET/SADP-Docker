<?php

use App\Livewire\Platform\Logs;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\LogEntry;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Logging\ContainerLogCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->withoutDefer();
    config(['security.two_factor.required_for' => 'none']);
    InstanceSettings::forceCreate([
        'id' => 0,
        'is_log_collection_enabled' => true,
        'is_system_log_collection_enabled' => true,
        'is_container_log_collection_enabled' => true,
    ]);
    Once::flush();

    $this->rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->platformAdmin = User::factory()->create();
    $this->rootTeam->members()->attach($this->platformAdmin->id, ['role' => 'admin']);
});

function actAsRetentionAdmin(): void
{
    test()->actingAs(test()->platformAdmin);
    session(['currentTeam' => test()->rootTeam]);
}

function updateRetentionSettings(array $attributes): void
{
    InstanceSettings::query()->whereKey(0)->update($attributes);
    Once::flush();
}

test('audit retention follows the instance setting but never drops below the policy minimum', function (int $configured, int $expected) {
    updateRetentionSettings(['audit_log_retention_days' => $configured]);

    expect(AuditEvent::retentionDays())->toBe($expected);
})->with([
    'below minimum' => [30, 365],
    'at minimum' => [365, 365],
    'longer' => [730, 730],
]);

test('audit pruning keeps events inside a longer configured retention', function () {
    updateRetentionSettings(['audit_log_retention_days' => 730]);
    $kept = AuditEvent::factory()->create(['created_at' => now()->subDays(400)]);
    $expired = AuditEvent::factory()->create(['created_at' => now()->subDays(731)]);

    AuditEvent::pruneExpired();

    expect(AuditEvent::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(AuditEvent::query()->find($expired->id))->toBeNull();
});

test('the audit log file channel keeps files at least as long as the audit minimum', function () {
    expect(config('logging.channels.audit.days'))->toBeGreaterThanOrEqual((int) config('security.audit.min_retention_days'));
});

test('system and container log retention cannot go below the policy minimum', function () {
    updateRetentionSettings(['log_retention_days' => 7]);
    $kept = LogEntry::factory()->create(['created_at' => now()->subDays(100)]);
    LogEntry::factory()->create(['created_at' => now()->subDays(181)]);

    expect(LogEntry::retentionDays())->toBe(180)
        ->and(LogEntry::pruneOlderThan(7))->toBe(1)
        ->and(LogEntry::query()->pluck('id')->all())->toBe([$kept->id]);
});

test('the migration raises stored log retention below the minimum', function () {
    DB::table('instance_settings')->where('id', 0)->update(['log_retention_days' => 7]);

    $migration = require database_path('migrations/2026_10_08_120001_raise_minimum_log_retention_on_instance_settings.php');
    $migration->up();

    expect(DB::table('instance_settings')->where('id', 0)->value('log_retention_days'))->toBe(180);
});

test('database cleanup removes sessions past the absolute session lifetime', function () {
    config(['security.session.absolute_timeout_minutes' => 720]);
    DB::table('sessions')->insert([
        ['id' => 'expired', 'payload' => '', 'last_activity' => now()->subMinutes(721)->timestamp],
        ['id' => 'active', 'payload' => '', 'last_activity' => now()->subMinutes(30)->timestamp],
    ]);

    $this->artisan('cleanup:database --yes')
        ->expectsOutputToContain('Delete 1 entries from sessions.')
        ->assertSuccessful();

    expect(DB::table('sessions')->pluck('id')->all())->toBe(['active']);
});

test('audit collection stays on when the master and audit switches are turned off', function () {
    actAsRetentionAdmin();

    Livewire::test(Logs::class)
        ->assertSet('isAuditLogCollectionEnabled', true)
        ->set('isLogCollectionEnabled', false)
        ->set('isAuditLogCollectionEnabled', false)
        ->set('logRetentionDays', 180)
        ->call('saveCollection')
        ->assertHasNoErrors()
        ->assertSet('isAuditLogCollectionEnabled', true);

    $settings = InstanceSettings::query()->find(0);
    expect($settings->is_audit_log_collection_enabled)->toBeTrue()
        ->and($settings->isAuditLogCollectionActive())->toBeTrue()
        ->and($settings->isSystemLogCollectionActive())->toBeFalse();

    auditLog('ui.project.updated', ['team_id' => $this->rootTeam->id]);
    expect(AuditEvent::query()->where('event', 'ui.project.updated')->exists())->toBeTrue();
});

test('the logs page explains that audit collection cannot be turned off and shows the retention minimum', function () {
    updateRetentionSettings(['log_retention_days' => 7]);
    actAsRetentionAdmin();

    Livewire::test(Logs::class)
        ->assertSet('logRetentionDays', 180)
        ->assertSee('always collected and cannot be turned off')
        ->assertSee('at least 180 days');
});

test('log exports include the audit result', function () {
    AuditEvent::factory()->create(['team_id' => $this->rootTeam->id, 'description' => 'Denied deploy', 'result' => 'denied']);
    actAsRetentionAdmin();

    $component = Livewire::test(Logs::class)->set('search', 'Denied deploy')->call('export', 'ndjson');
    $line = json_decode(trim(base64_decode(data_get($component->effects, 'download.content'))), true);

    $csvComponent = Livewire::test(Logs::class)->set('search', 'Denied deploy')->call('export', 'csv');
    $csv = base64_decode(data_get($csvComponent->effects, 'download.content'));

    expect($line['result'])->toBe('denied')
        ->and(strtok($csv, "\n"))->toBe('time,source,level,result,actor,resource,message,details');
});

test('system log messages and context are masked before they are stored', function () {
    Log::warning('Login failed for jane@example.com with password=hunter2', ['url' => 'https://ci:tok3n@git.example.com/repo.git']);

    $entry = LogEntry::query()->where('source', LogEntry::SOURCE_SYSTEM)->sole();

    expect($entry->message)->toBe('Login failed for j***@example.com with password=[REDACTED]')
        ->and($entry->context['url'])->toBe('https://ci:[REDACTED]@git.example.com/repo.git');
});

test('container log lines are masked before they are stored', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $dockerPs = json_encode(['ID' => 'abc', 'Names' => 'web-1', 'Labels' => 'coolify.managed=true,coolify.type=application,coolify.applicationUuid=app-uuid,coolify.resourceName=shop']);
    $logs = implode("\n", [
        now()->subMinute()->utc()->format('Y-m-d\TH:i:s.u\Z').' user jane@example.com signed in',
        now()->subMinute()->utc()->format('Y-m-d\TH:i:s.u\Z').' Authorization: Bearer abc.def',
        now()->subMinute()->utc()->format('Y-m-d\TH:i:s.u\Z').' -----BEGIN PRIVATE KEY-----',
        now()->subMinute()->utc()->format('Y-m-d\TH:i:s.u\Z').' MIIEvQIBADANBgkqhkiG9w0BAQEFAASC',
        now()->subMinute()->utc()->format('Y-m-d\TH:i:s.u\Z').' -----END PRIVATE KEY-----',
    ]);
    $collector = new ContainerLogCollector(fn (array $commands): ?string => str_starts_with($commands[0], 'docker ps') ? $dockerPs : $logs);

    $collector->collect($server);

    expect(LogEntry::query()->where('source', LogEntry::SOURCE_CONTAINER)->orderBy('id')->pluck('message')->all())->toBe([
        'user j***@example.com signed in',
        'Authorization: Bearer [REDACTED]',
        '[REDACTED PRIVATE KEY]',
        '[REDACTED PRIVATE KEY]',
        '[REDACTED PRIVATE KEY]',
    ]);
});
