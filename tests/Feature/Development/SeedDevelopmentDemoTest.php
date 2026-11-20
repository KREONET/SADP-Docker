<?php

use App\Actions\Development\SeedDevelopmentDemo;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\LogEntry;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    User::factory()->create(['id' => 0, 'email' => 'test@example.com']);
    Server::factory()->create(['id' => 0, 'team_id' => 0]);
    Project::factory()->create(['team_id' => 0, 'name' => 'My first project']);
    Http::fake(['loki:3100/*' => Http::response('', 204)]);
});

test('the demo command only runs in development mode', function () {
    $this->artisan('dev:demo', ['--without-inventory' => true])
        ->expectsOutputToContain('only run in development mode')
        ->assertFailed();

    expect(User::query()->where('email', 'owner@demo.test')->exists())->toBeFalse();
    Http::assertNothingSent();
});

test('the demo command creates users of every role, demo logs and turns log collection on', function () {
    config(['app.env' => 'local']);

    $this->artisan('dev:demo', ['--without-inventory' => true])
        ->expectsOutputToContain('Demo data is ready.')
        ->expectsOutputToContain('owner@demo.test  owner of Demo Tenant')
        ->assertSuccessful();

    $tenant = Team::query()->where('name', SeedDevelopmentDemo::TENANT_TEAM)->firstOrFail();
    $owner = User::query()->where('email', 'owner@demo.test')->firstOrFail();
    $support = User::query()->where('email', 'support@demo.test')->firstOrFail();

    expect($tenant->members()->whereKey($owner->id)->first()->pivot->role)->toBe('owner')
        ->and(Team::find(0)->members()->whereKey($support->id)->first()->pivot->role)->toBe('member')
        ->and(Hash::check(SeedDevelopmentDemo::PASSWORD, $owner->password))->toBeTrue()
        ->and($owner->email_verified_at)->not->toBeNull()
        ->and($owner->current_team_id)->toBe($tenant->id)
        ->and($owner->teams()->where('personal_team', true)->where('show_boarding', true)->exists())->toBeFalse()
        ->and(Project::query()->where('team_id', $tenant->id)->where('name', 'Tenant Shop')->exists())->toBeTrue();

    expect(AuditEvent::query()->where('metadata->demo', true)->count())->toBe(90)
        ->and(AuditEvent::query()->where('event', 'auth.user.login_failed')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('team_id', $tenant->id)->exists())->toBeTrue()
        ->and(LogEntry::query()->where('source', 'system')->count())->toBe(45)
        ->and(LogEntry::query()->where('source', 'container')->count())->toBe(140)
        ->and(LogEntry::query()->where('context->access_key', '[REDACTED]')->exists())->toBeTrue()
        ->and(LogEntry::query()->where('logged_at', '<', now()->subDays(SeedDevelopmentDemo::HISTORY_DAYS))->exists())->toBeFalse();

    $settings = InstanceSettings::query()->find(0);
    expect($settings->isAuditLogCollectionActive())->toBeTrue()
        ->and($settings->isSystemLogCollectionActive())->toBeTrue()
        ->and($settings->isContainerLogCollectionActive())->toBeTrue()
        ->and($settings->isLokiShippingActive())->toBeTrue()
        ->and($settings->loki_url)->toBe('http://loki:3100')
        ->and($settings->loki_last_error)->toBeNull()
        ->and(LogEntry::query()->whereNull('loki_shipped_at')->count())->toBe(0)
        ->and(AuditEvent::query()->where('metadata->demo', true)->whereNull('loki_shipped_at')->count())->toBe(0);

    Http::assertSent(fn ($request) => $request->url() === 'http://loki:3100/loki/api/v1/push');
});

test('ids of the demo rows follow time so loki receives each stream in order', function () {
    config(['app.env' => 'local']);

    SeedDevelopmentDemo::run('http://loki:3100', false);

    $times = LogEntry::query()->where('source', 'container')->orderBy('id')->pluck('logged_at')->map->getTimestamp()->all();
    $sorted = $times;
    sort($sorted);

    expect($times)->toBe($sorted);
});

test('running the demo again replaces demo rows, keeps real rows, and does not resend history', function () {
    config(['app.env' => 'local']);
    $real = LogEntry::factory()->create(['message' => 'real warning']);

    SeedDevelopmentDemo::run('http://loki:3100', false);
    $firstRunPushes = count(Http::recorded());
    $result = SeedDevelopmentDemo::run('http://loki:3100', false);

    expect(AuditEvent::query()->where('metadata->demo', true)->count())->toBe(90)
        ->and(LogEntry::query()->where('context->demo', true)->count())->toBe(185)
        ->and($real->fresh())->not->toBeNull()
        ->and(User::query()->where('email', 'owner@demo.test')->count())->toBe(1)
        ->and(count(Http::recorded()))->toBe($firstRunPushes)
        ->and($result['loki'])->toContain('already has the demo history');
});

test('an empty loki url keeps shipping off', function () {
    config(['app.env' => 'local']);

    $result = SeedDevelopmentDemo::run('', false);

    expect(InstanceSettings::query()->find(0)->isLokiShippingActive())->toBeFalse()
        ->and($result['loki'])->toContain('shipping is off');
    Http::assertNothingSent();
});

test('the demo creates nothing while the base development data is still missing', function (string $missing) {
    config(['app.env' => 'local']);
    match ($missing) {
        'sample project' => Project::query()->where('team_id', 0)->delete(),
        'localhost server' => Server::query()->whereKey(0)->forceDelete(),
        'root user' => User::query()->whereKey(0)->delete(),
    };

    expect(fn () => SeedDevelopmentDemo::run('http://loki:3100', false))
        ->toThrow(RuntimeException::class, 'base development data does not exist yet');

    // A too early run must not leave rows behind that take the fixed ids of the base seeders.
    expect(Project::query()->where('name', 'Tenant Shop')->exists())->toBeFalse()
        ->and(Team::query()->where('name', SeedDevelopmentDemo::TENANT_TEAM)->exists())->toBeFalse()
        ->and(User::query()->where('email', 'owner@demo.test')->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('metadata->demo', true)->exists())->toBeFalse();
    Http::assertNothingSent();
})->with(['sample project', 'localhost server', 'root user']);
