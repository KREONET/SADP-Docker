<?php

use App\Jobs\ShipLogsToLokiJob;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\LogEntry;
use App\Services\Logging\LokiClient;
use App\Services\Logging\LokiStreamBuilder;
use App\Services\Logging\ThrottledSecurityAlert;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02T09:30:00Z'));
    InstanceSettings::forceCreate([
        'id' => 0,
        'instance_name' => 'Acme Prod',
        'is_log_collection_enabled' => true,
        'is_audit_log_collection_enabled' => true,
        'is_system_log_collection_enabled' => true,
        'is_container_log_collection_enabled' => true,
        'is_loki_shipping_enabled' => true,
        'loki_url' => 'https://loki.example.com',
        'loki_username' => 'user',
        'loki_password' => 'secret',
        'loki_tenant_id' => 'tenant-a',
        'loki_shipping_started_at' => now()->subHour(),
    ]);
    Once::flush();
});

function lokiStreams(): array
{
    $streams = [];
    foreach (Http::recorded() as [$request]) {
        foreach (data_get($request->data(), 'streams', []) as $stream) {
            $streams[] = $stream;
        }
    }

    return $streams;
}

test('unshipped audit events and log lines are pushed to loki and marked as shipped', function () {
    Http::fake(['loki.example.com/*' => Http::response('', 204)]);
    $audit = AuditEvent::factory()->create(['event' => 'auth.user.login_succeeded', 'actor_email' => 'admin@example.com', 'description' => 'User login']);
    $system = LogEntry::factory()->create(['message' => 'Queue is slow', 'level' => 'warning']);
    $container = LogEntry::factory()->container('web-1')->create(['message' => 'GET / 200']);

    (new ShipLogsToLokiJob)->handle();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://loki.example.com/loki/api/v1/push'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('user:secret'))
        && $request->hasHeader('X-Scope-OrgID', 'tenant-a'));

    $streams = collect(lokiStreams());
    $auditStream = $streams->firstWhere('stream.source', 'audit');
    $containerStream = $streams->firstWhere('stream.source', 'container');

    expect($auditStream['stream'])->toBe(['service_name' => 'coolify', 'instance' => 'acme-prod', 'level' => 'info', 'source' => 'audit'])
        ->and(json_decode($auditStream['values'][0][1], true))->toMatchArray(['msg' => 'User login', 'event' => 'auth.user.login_succeeded', 'actor_email' => 'admin@example.com'])
        ->and($auditStream['values'][0][0])->toBe(LokiStreamBuilder::nanoseconds($audit->created_at))
        ->and($containerStream['stream']['app'])->toBe('demo-app')
        ->and(json_decode($containerStream['values'][0][1], true))->toMatchArray(['msg' => 'GET / 200', 'container' => 'web-1'])
        ->and($audit->fresh()->loki_shipped_at)->not->toBeNull()
        ->and($system->fresh()->loki_shipped_at)->not->toBeNull()
        ->and($container->fresh()->loki_shipped_at)->not->toBeNull();

    $settings = InstanceSettings::query()->find(0);
    expect($settings->loki_last_pushed_at)->not->toBeNull()
        ->and($settings->loki_last_error)->toBeNull();
});

test('rows created before shipping was turned on are not sent', function () {
    Http::fake(['loki.example.com/*' => Http::response('', 204)]);
    $old = LogEntry::factory()->create(['created_at' => now()->subHours(2)]);

    (new ShipLogsToLokiJob)->handle();

    Http::assertNothingSent();
    expect($old->fresh()->loki_shipped_at)->toBeNull();
});

test('sources that are switched off are not sent but audit events always are', function () {
    Http::fake(['loki.example.com/*' => Http::response('', 204)]);
    InstanceSettings::query()->find(0)->update(['is_audit_log_collection_enabled' => false, 'is_container_log_collection_enabled' => false]);
    AuditEvent::factory()->create();
    LogEntry::factory()->container()->create();
    $system = LogEntry::factory()->create();

    (new ShipLogsToLokiJob)->handle();

    expect(collect(lokiStreams())->pluck('stream.source')->unique()->sort()->values()->all())->toBe(['audit', 'system'])
        ->and($system->fresh()->loki_shipped_at)->not->toBeNull()
        ->and(AuditEvent::query()->whereNull('loki_shipped_at')->count())->toBe(0)
        ->and(LogEntry::query()->where('source', LogEntry::SOURCE_CONTAINER)->whereNotNull('loki_shipped_at')->count())->toBe(0);
});

test('nothing is sent while the master switch or loki shipping is off', function (array $settings) {
    Http::fake();
    InstanceSettings::query()->find(0)->update($settings);
    LogEntry::factory()->create();

    (new ShipLogsToLokiJob)->handle();

    Http::assertNothingSent();
})->with([
    'master switch off' => [['is_log_collection_enabled' => false]],
    'loki shipping off' => [['is_loki_shipping_enabled' => false]],
    'no loki url' => [['loki_url' => null]],
]);

test('server errors keep rows for the next run and record the error', function (int $status) {
    Http::fake(['loki.example.com/*' => Http::response('ingester unavailable', $status)]);
    $entry = LogEntry::factory()->create();

    (new ShipLogsToLokiJob)->handle();

    expect($entry->fresh()->loki_shipped_at)->toBeNull()
        ->and(InstanceSettings::query()->find(0)->loki_last_error)->toContain("HTTP {$status}");
})->with([429, 500, 503]);

test('connection failures keep rows for the next run', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));
    $entry = LogEntry::factory()->create();

    (new ShipLogsToLokiJob)->handle();

    expect($entry->fresh()->loki_shipped_at)->toBeNull()
        ->and(InstanceSettings::query()->find(0)->loki_last_error)->toContain('Could not reach Loki');
});

test('a batch loki rejects stays unshipped and is retried on the next run', function () {
    Http::fake(['loki.example.com/*' => Http::response('entry too far behind', 400)]);
    $entry = LogEntry::factory()->create();

    (new ShipLogsToLokiJob)->handle();

    expect($entry->fresh()->loki_shipped_at)->toBeNull()
        ->and($entry->fresh()->loki_attempts)->toBe(1)
        ->and(Http::recorded())->toHaveCount(1)
        ->and(InstanceSettings::query()->find(0)->loki_last_error)->toContain('HTTP 400')->toContain('attempt 1 of '.ShipLogsToLokiJob::MAX_REJECTED_ATTEMPTS);
});

test('a batch loki keeps rejecting is parked unshipped so it cannot block newer rows', function () {
    Http::fake(['loki.example.com/*' => Http::sequence()
        ->pushStatus(400)->pushStatus(400)->pushStatus(400)->pushStatus(400)->pushStatus(400)
        ->pushStatus(204)]);
    $rejected = LogEntry::factory()->create();

    foreach (range(1, ShipLogsToLokiJob::MAX_REJECTED_ATTEMPTS) as $run) {
        (new ShipLogsToLokiJob)->handle();
    }

    expect($rejected->fresh()->loki_shipped_at)->toBeNull()
        ->and($rejected->fresh()->loki_attempts)->toBe(ShipLogsToLokiJob::MAX_REJECTED_ATTEMPTS)
        ->and(InstanceSettings::query()->find(0)->loki_last_error)->toContain('without further retries');

    $newer = LogEntry::factory()->create();
    (new ShipLogsToLokiJob)->handle();

    expect($newer->fresh()->loki_shipped_at)->not->toBeNull()
        ->and($rejected->fresh()->loki_shipped_at)->toBeNull()
        ->and(Http::recorded())->toHaveCount(ShipLogsToLokiJob::MAX_REJECTED_ATTEMPTS + 1);
});

test('shipping failures send a throttled security alert', function () {
    Http::fake(['loki.example.com/*' => Http::response('ingester unavailable', 503)]);
    LogEntry::factory()->create();

    expect(Cache::has(ThrottledSecurityAlert::cacheKey('log_shipping_failed')))->toBeFalse();

    (new ShipLogsToLokiJob)->handle();

    expect(Cache::has(ThrottledSecurityAlert::cacheKey('log_shipping_failed')))->toBeTrue();
});

test('successful shipping sends no security alert', function () {
    Http::fake(['loki.example.com/*' => Http::response('', 204)]);
    LogEntry::factory()->create();

    (new ShipLogsToLokiJob)->handle();

    expect(Cache::has(ThrottledSecurityAlert::cacheKey('log_shipping_failed')))->toBeFalse();
});

test('loki lines mask e-mail addresses and secrets in free text', function () {
    Http::fake(['loki.example.com/*' => Http::response('', 204)]);
    LogEntry::factory()->create(['message' => 'login for jane@example.com with password=hunter2', 'context' => ['header' => 'Bearer abc.def.ghi']]);

    (new ShipLogsToLokiJob)->handle();

    $line = json_decode(collect(lokiStreams())->firstWhere('stream.source', 'system')['values'][0][1], true);
    expect($line['msg'])->toBe('login for j***@example.com with password=[REDACTED]')
        ->and($line['context']['header'])->toBe('Bearer [REDACTED]');
});

test('large backlogs are sent in batches', function () {
    Http::fake(['loki.example.com/*' => Http::response('', 204)]);
    LogEntry::factory()->count(ShipLogsToLokiJob::BATCH_SIZE + 5)->create();

    (new ShipLogsToLokiJob)->handle();

    expect(Http::recorded())->toHaveCount(2)
        ->and(LogEntry::query()->whereNull('loki_shipped_at')->count())->toBe(0);
});

test('the push url accepts a base url or a full push url', function (string $url) {
    expect(LokiClient::pushUrl($url))->toBe('https://loki.example.com/loki/api/v1/push');
})->with(['https://loki.example.com', 'https://loki.example.com/', 'https://loki.example.com/loki/api/v1/push']);

test('loki timestamps are nanoseconds', function () {
    expect(LokiStreamBuilder::nanoseconds(CarbonImmutable::parse('2026-10-02T09:30:00.123456Z')))->toBe('1790933400123456000');
});
