<?php

use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Services\Logging\AuditChainVerifier;
use App\Services\Logging\ThrottledSecurityAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function auditChainRows(int $count): array
{
    foreach (range(1, $count) as $number) {
        auditLog("ui.project.updated_{$number}", ['team_id' => test()->team->id, 'project_name' => "Project {$number}"]);
    }

    return AuditEvent::query()->orderBy('id')->get()->all();
}

test('each audit event links to the hash of the previous event', function () {
    [$first, $second, $third] = auditChainRows(3);

    expect($first->previous_hash)->toBeNull()
        ->and($first->hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($second->previous_hash)->toBe($first->hash)
        ->and($third->previous_hash)->toBe($second->hash)
        ->and($third->hash)->toBe(hash('sha256', $second->hash.AuditEvent::canonicalJson($third->canonicalFields())));
});

test('the stored hash still verifies after the row is read back from the database', function () {
    AuditEvent::factory()->create([
        'team_id' => $this->team->id,
        'actor_id' => '7',
        'metadata' => ['z' => 1, 'a' => ['nested' => 1.5, 'list' => [3, 1, 2]], 'unicode' => '한국어/slash'],
    ]);

    $event = AuditEvent::query()->sole();

    expect($event->computeHash())->toBe($event->hash);
});

test('audit:verify succeeds for an intact chain', function () {
    auditChainRows(3);

    $this->artisan('audit:verify')
        ->expectsOutputToContain('Audit chain intact: 3 events verified.')
        ->assertSuccessful();
});

test('audit:verify reports a row whose contents were changed', function () {
    [, $second] = auditChainRows(3);
    DB::table('audit_events')->where('id', $second->id)->update(['description' => 'Tampered']);

    $this->artisan('audit:verify')
        ->expectsOutputToContain("Audit chain broken at event #{$second->id}: the stored hash does not match the row contents.")
        ->assertFailed();
});

test('audit:verify reports a row removed from the middle of the chain', function () {
    [, $second, $third] = auditChainRows(3);
    DB::table('audit_events')->where('id', $second->id)->delete();

    $result = app(AuditChainVerifier::class)->verify();

    expect($result['intact'])->toBeFalse()
        ->and($result['broken_id'])->toBe($third->id)
        ->and($result['reason'])->toContain('previous_hash does not match');

    $this->artisan('audit:verify')->assertFailed();
});

test('the oldest remaining row anchors the chain after retention pruning', function () {
    $this->travelTo(now()->subDays(400));
    auditChainRows(2);
    $this->travelBack();
    auditChainRows(2);

    expect(AuditEvent::pruneExpired())->toBe(2)
        ->and(AuditEvent::query()->count())->toBe(2)
        ->and(AuditEvent::query()->orderBy('id')->first()->previous_hash)->not->toBeNull();

    $this->artisan('audit:verify')->assertSuccessful();
});

test('rows written before the chain existed are skipped at the start', function () {
    DB::table('audit_events')->insert([
        'event' => 'ui.project.legacy',
        'source' => 'ui',
        'action' => 'legacy',
        'level' => 'info',
        'actor_type' => 'user',
        'description' => 'Legacy',
        'created_at' => now()->subDay(),
    ]);
    auditChainRows(2);

    $result = app(AuditChainVerifier::class)->verify();

    expect($result)->toMatchArray(['intact' => true, 'verified' => 2, 'unchained' => 1]);
});

test('audit events cannot be updated or deleted through the model', function () {
    [$event] = auditChainRows(1);
    $description = $event->description;

    expect(fn () => $event->update(['description' => 'Changed']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class)
        ->and(fn () => AuditEvent::query()->whereKey($event->id)->update(['description' => 'Changed']))->toThrow(LogicException::class)
        ->and(AuditEvent::query()->find($event->id)->description)->toBe($description);
});

test('loki bookkeeping columns stay writable', function () {
    [$event] = auditChainRows(1);

    AuditEvent::query()->whereKey($event->id)->update(['loki_shipped_at' => now()]);

    expect($event->fresh()->loki_shipped_at)->not->toBeNull();
    $this->artisan('audit:verify')->assertSuccessful();
});

test('the result comes from the context or is derived from the event and level', function (string $event, array $context, string $level, string $expected) {
    auditLog($event, ['team_id' => $this->team->id, ...$context], $level);

    expect(AuditEvent::query()->sole()->result)->toBe($expected);
})->with([
    'explicit success' => ['ui.project.updated', ['result' => 'success'], 'warning', 'success'],
    'explicit denied' => ['ui.project.updated', ['result' => 'denied'], 'info', 'denied'],
    'invalid explicit value' => ['ui.project.updated', ['result' => 'maybe'], 'info', 'success'],
    'failed event' => ['auth.user.login_failed', [], 'info', 'failure'],
    'blocked event' => ['api.request.blocked', [], 'info', 'denied'],
    'locked event' => ['auth.user.locked', [], 'info', 'denied'],
    'unlocked is not locked' => ['auth.user.unlocked', [], 'info', 'success'],
    'warning level' => ['webhook.github.signature_check', [], 'warning', 'failure'],
    'plain info' => ['ui.project.created', [], 'info', 'success'],
]);

test('failed login e-mail addresses are masked in the audit trail', function () {
    auditLog('auth.user.login_failed', ['attempted_email' => 'jane.doe@example.com'], 'warning');

    expect(AuditEvent::query()->sole()->metadata['attempted_email'])->toBe('j***@example.com');
});

test('a failed audit write logs an error and sends one throttled security alert', function () {
    Log::spy();
    Schema::rename('audit_events', 'unavailable_audit_events');

    try {
        auditLog('ui.project.updated', ['team_id' => $this->team->id]);
        auditLog('ui.project.deleted', ['team_id' => $this->team->id]);
    } finally {
        Schema::rename('unavailable_audit_events', 'audit_events');
    }

    Log::shouldHaveReceived('error')->twice()->with('Audit event persistence failed', Mockery::type('array'));
    expect(Cache::has(ThrottledSecurityAlert::cacheKey('audit_write_failed')))->toBeTrue();
});

test('throttled security alerts are sent once per window', function () {
    expect(ThrottledSecurityAlert::send('audit_write_failed', 'Audit events could not be stored'))->toBeTrue()
        ->and(ThrottledSecurityAlert::send('audit_write_failed', 'Audit events could not be stored'))->toBeFalse();

    $this->travel(ThrottledSecurityAlert::WINDOW_MINUTES + 1)->minutes();

    expect(ThrottledSecurityAlert::send('audit_write_failed', 'Audit events could not be stored'))->toBeTrue();
});

test('the audit events api exposes the result', function () {
    AuditEvent::factory()->create(['team_id' => $this->team->id, 'result' => 'denied']);

    $token = $this->user->createToken('audit-read', ['read']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    auth()->logout();
    auth()->forgetGuards();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/audit-events?source=ui&action=updated')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.result', 'denied');
});
