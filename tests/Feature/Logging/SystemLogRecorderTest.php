<?php

use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\LogEntry;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    InstanceSettings::forceCreate([
        'id' => 0,
        'is_log_collection_enabled' => true,
        'is_system_log_collection_enabled' => true,
    ]);
    Once::flush();
});

test('warnings and errors are stored with sensitive context redacted', function () {
    Log::warning('Proxy restart is slow', ['server_uuid' => 'srv-1', 'api_token' => 'plain-secret']);
    Log::error('Deployment failed', ['exception' => new RuntimeException('docker exited with 1')]);

    $entries = LogEntry::query()->where('source', LogEntry::SOURCE_SYSTEM)->orderBy('id')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0])->level->toBe('warning')->message->toBe('Proxy restart is slow')
        ->and($entries[0]->context)->toBe(['server_uuid' => 'srv-1', 'api_token' => '[REDACTED]'])
        ->and($entries[1]->level)->toBe('error')
        ->and(data_get($entries[1]->context, 'exception.class'))->toBe(RuntimeException::class)
        ->and(data_get($entries[1]->context, 'exception.message'))->toBe('docker exited with 1');
});

test('info and debug messages are not stored', function () {
    Log::info('Routine message');
    Log::debug('Noisy message');

    expect(LogEntry::query()->count())->toBe(0);
});

test('nothing is stored when the master switch or the system source is off', function (array $settings) {
    InstanceSettings::query()->find(0)->update($settings);

    Log::error('Should not be stored');

    expect(LogEntry::query()->count())->toBe(0);
})->with([
    'master switch off' => [['is_log_collection_enabled' => false]],
    'system source off' => [['is_system_log_collection_enabled' => false]],
]);

test('php deprecation notices are not stored but warnings mentioning deprecation are', function () {
    putenv('LOG_DEPRECATIONS_WHILE_TESTING=true');

    try {
        (new HandleExceptions)->handleDeprecationError('Vendor\\Thing::__construct(): Implicitly marking parameter as nullable is deprecated', __FILE__, __LINE__);
    } finally {
        putenv('LOG_DEPRECATIONS_WHILE_TESTING');
    }
    Log::warning('The v1 webhook endpoint is deprecated and was called');

    expect(LogEntry::query()->pluck('message')->all())->toBe(['The v1 webhook endpoint is deprecated and was called']);
});

test('warning audit events are not copied into the system logs', function () {
    auditLog('auth.user.login_failed', ['attempted_email' => 'someone@example.com'], 'warning');

    expect(LogEntry::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event', 'auth.user.login_failed')->exists())->toBeTrue();
});

test('a database failure while storing a log line does not break the caller', function () {
    InstanceSettings::query()->find(0);
    Schema::drop('log_entries');

    Log::error('Database is gone');

    expect(true)->toBeTrue();
});

test('long messages are truncated', function () {
    Log::error(str_repeat('x', LogEntry::MAX_MESSAGE_LENGTH + 100));

    expect(strlen(LogEntry::query()->firstOrFail()->message))->toBe(LogEntry::MAX_MESSAGE_LENGTH);
});

test('collected log lines older than the retention period are pruned', function () {
    LogEntry::factory()->create(['created_at' => now()->subDays(8)]);
    $recent = LogEntry::factory()->create(['created_at' => now()->subDays(6)]);

    expect(LogEntry::pruneOlderThan(instanceSettings()->logRetentionDays()))->toBe(1)
        ->and(LogEntry::query()->pluck('id')->all())->toBe([$recent->id]);
});
