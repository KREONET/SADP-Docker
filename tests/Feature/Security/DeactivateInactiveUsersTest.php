<?php

use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0]);
    Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    config()->set('security.accounts.deactivate_inactive_after_days', 90);
});

function inactiveTestUser(array $attributes): User
{
    $user = User::factory()->create();
    $user->forceFill($attributes)->saveQuietly();

    return $user->fresh();
}

test('accounts without a login inside the window are deactivated', function () {
    $stale = inactiveTestUser(['last_login_at' => now()->subDays(120)]);
    $recent = inactiveTestUser(['last_login_at' => now()->subDays(10)]);

    $this->artisan('users:deactivate-inactive')->assertSuccessful();

    expect($stale->fresh()->isDeactivated())->toBeTrue()
        ->and($recent->fresh()->isDeactivated())->toBeFalse();

    $event = AuditEvent::query()->where('event', 'user.account.deactivated_inactive')->sole();
    expect($event->metadata['target_user_id'])->toBe($stale->id)
        ->and($event->metadata['inactive_after_days'])->toBe(90)
        ->and(AuditEvent::query()->where('event', 'user.account.deactivated')->count())->toBe(1);
});

test('accounts that never signed in are measured from their last update or creation', function () {
    $neverLoggedInOld = inactiveTestUser(['last_login_at' => null, 'created_at' => now()->subDays(200), 'updated_at' => now()->subDays(200)]);
    $neverLoggedInNew = inactiveTestUser(['last_login_at' => null, 'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5)]);
    // Accounts from before last_login_at was tracked: the legacy login touched updated_at.
    $legacyActive = inactiveTestUser(['last_login_at' => null, 'created_at' => now()->subDays(400), 'updated_at' => now()->subDays(3)]);

    $this->artisan('users:deactivate-inactive')->assertSuccessful();

    expect($neverLoggedInOld->fresh()->isDeactivated())->toBeTrue()
        ->and($neverLoggedInNew->fresh()->isDeactivated())->toBeFalse()
        ->and($legacyActive->fresh()->isDeactivated())->toBeFalse();
});

test('the root user is never deactivated', function () {
    $root = User::factory()->create(['id' => 0]);
    $root->forceFill(['last_login_at' => now()->subYears(2)])->saveQuietly();

    $this->artisan('users:deactivate-inactive')->assertSuccessful();

    expect($root->fresh()->isDeactivated())->toBeFalse();
});

test('a zero day setting disables deactivation', function () {
    config()->set('security.accounts.deactivate_inactive_after_days', 0);
    $stale = inactiveTestUser(['last_login_at' => now()->subYears(2)]);

    $this->artisan('users:deactivate-inactive')->assertSuccessful();

    expect($stale->fresh()->isDeactivated())->toBeFalse();
});

test('a dry run lists accounts without deactivating them', function () {
    $stale = inactiveTestUser(['last_login_at' => now()->subDays(120)]);

    $this->artisan('users:deactivate-inactive', ['--dry-run' => true])
        ->expectsOutputToContain($stale->email)
        ->assertSuccessful();

    expect($stale->fresh()->isDeactivated())->toBeFalse();
});

test('already deactivated accounts are skipped', function () {
    $deactivatedAt = now()->subDays(30)->startOfSecond();
    $stale = inactiveTestUser(['last_login_at' => now()->subDays(120), 'deactivated_at' => $deactivatedAt]);

    $this->artisan('users:deactivate-inactive')->assertSuccessful();

    expect($stale->fresh()->deactivated_at->equalTo($deactivatedAt))->toBeTrue()
        ->and(AuditEvent::query()->where('event', 'user.account.deactivated_inactive')->exists())->toBeFalse();
});

test('the command is scheduled daily on one server', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'users:deactivate-inactive'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('45 2 * * *')
        ->and($event->onOneServer)->toBeTrue();
});
