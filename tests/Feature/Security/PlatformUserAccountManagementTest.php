<?php

use App\Livewire\Platform\Groups;
use App\Livewire\Platform\Users;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);
    Cache::flush();

    $this->rootTeam = Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    // The root user joins the root team as owner when it is created.
    $this->root = User::factory()->create(['id' => 0]);
    if (! $this->rootTeam->members()->where('users.id', 0)->exists()) {
        $this->rootTeam->members()->attach(0, ['role' => 'owner']);
    }

    $this->platformAdmin = User::factory()->create();
    $this->rootTeam->members()->attach($this->platformAdmin->id, ['role' => 'admin']);

    $this->tenantTeam = Team::factory()->create(['name' => 'Tenant Team']);
    $this->tenant = User::factory()->create();
    $this->tenantTeam->members()->attach($this->tenant->id, ['role' => 'owner']);
});

function actAsPlatformAccountManager(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

test('platform admins can deactivate and reactivate a user after confirming their password', function (string $component) {
    actAsPlatformAccountManager($this->platformAdmin, $this->rootTeam);
    $token = (function () {
        session(['currentTeam' => $this->tenantTeam]);
        $token = $this->tenant->createToken('ci', ['read'], now()->addDay());
        session(['currentTeam' => $this->rootTeam]);

        return $token;
    })();

    Livewire::test($component)
        ->call('deactivateUser', $this->tenant->id, 'wrong-password')
        ->assertReturned('The provided password is incorrect.');
    expect($this->tenant->fresh()->isDeactivated())->toBeFalse();

    Livewire::test($component)
        ->call('deactivateUser', $this->tenant->id, 'password')
        ->assertReturned(true)
        ->assertDispatched('success');

    expect($this->tenant->fresh()->isDeactivated())->toBeTrue()
        ->and($this->tenant->tokens()->whereKey($token->accessToken->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('event', 'platform.user.deactivated')->where('result', 'success')->exists())->toBeTrue();

    Livewire::test($component)->call('reactivateUser', $this->tenant->id)->assertDispatched('success');

    $tenant = $this->tenant->fresh();
    expect($tenant->isDeactivated())->toBeFalse()
        ->and($tenant->last_login_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('event', 'platform.user.reactivated')->exists())->toBeTrue();
})->with(['groups page' => [Groups::class], 'users page' => [Users::class]]);

test('platform admins can unlock a locked account', function () {
    $this->tenant->forceFill(['failed_login_attempts' => 5, 'locked_until' => now()->addMinutes(30)])->saveQuietly();
    actAsPlatformAccountManager($this->platformAdmin, $this->rootTeam);

    Livewire::test(Users::class)
        ->set('accountFilter', 'locked')
        ->assertSee($this->tenant->email)
        ->assertSee('Unlock')
        ->call('unlockUser', $this->tenant->id)
        ->assertDispatched('success');

    $tenant = $this->tenant->fresh();
    expect($tenant->isLockedOut())->toBeFalse()
        ->and($tenant->failed_login_attempts)->toBe(0)
        ->and(AuditEvent::query()->where('event', 'platform.user.unlocked')->where('result', 'success')->exists())->toBeTrue();
});

test('the root user and the acting admin cannot be changed', function (string $method) {
    $this->root->forceFill(['locked_until' => now()->addMinutes(30)])->saveQuietly();
    $this->platformAdmin->forceFill(['locked_until' => now()->addMinutes(30)])->saveQuietly();
    actAsPlatformAccountManager($this->platformAdmin, $this->rootTeam);

    $arguments = $method === 'deactivateUser' ? ['password'] : [];

    Livewire::test(Users::class)->call($method, 0, ...$arguments);
    Livewire::test(Users::class)->call($method, $this->platformAdmin->id, ...$arguments);

    expect($this->root->fresh()->isDeactivated())->toBeFalse()
        ->and($this->root->fresh()->isLockedOut())->toBeTrue()
        ->and($this->platformAdmin->fresh()->isDeactivated())->toBeFalse()
        ->and($this->platformAdmin->fresh()->isLockedOut())->toBeTrue()
        ->and(AuditEvent::query()->where('result', 'denied')->count())->toBe(2);
})->with(['deactivateUser', 'reactivateUser', 'unlockUser']);

test('non platform admins cannot open the account management pages', function () {
    actAsPlatformAccountManager($this->tenant, $this->tenantTeam);

    Livewire::test(Users::class)->assertForbidden();
    Livewire::test(Groups::class)->assertForbidden();
});

test('every account action re-checks the platform admin gate', function (string $method) {
    $victim = User::factory()->create();
    $this->tenantTeam->members()->attach($victim->id, ['role' => 'member']);
    $victim->forceFill(['locked_until' => now()->addMinutes(30), 'deactivated_at' => $method === 'reactivateUser' ? now() : null])->saveQuietly();
    actAsPlatformAccountManager($this->platformAdmin, $this->rootTeam);
    $component = Livewire::test(Users::class);

    // Demoted after the page was opened.
    $this->rootTeam->members()->updateExistingPivot($this->platformAdmin->id, ['role' => 'member']);
    $this->platformAdmin->unsetRelation('teams');

    $arguments = $method === 'deactivateUser' ? ['password'] : [];
    $component->call($method, $victim->id, ...$arguments)->assertForbidden();

    $victim = $victim->fresh();
    expect($victim->isLockedOut())->toBeTrue()
        ->and($victim->isDeactivated())->toBe($method === 'reactivateUser');
})->with(['deactivateUser', 'reactivateUser', 'unlockUser']);

test('the account list shows last login and state', function () {
    $this->tenant->forceFill(['last_login_at' => now()->subDays(3), 'deactivated_at' => now()])->saveQuietly();
    actAsPlatformAccountManager($this->platformAdmin, $this->rootTeam);

    Livewire::test(Users::class)
        ->assertSee($this->tenant->email)
        ->assertSee('3 days ago')
        ->assertSee('Deactivated')
        ->assertSee('Reactivate');

    Livewire::test(Groups::class)
        ->call('selectTeam', $this->tenantTeam->id)
        ->assertSee('3 days ago')
        ->assertSee('Reactivate');
});

test('granting and revoking platform admin through the root team is audited and alerted', function () {
    Log::spy();
    $this->rootTeam->members()->attach($this->tenant->id, ['role' => 'member']);
    actAsPlatformAccountManager($this->platformAdmin, $this->rootTeam);

    Livewire::test(Groups::class)->call('updateMemberRole', 0, $this->tenant->id, 'admin');
    Livewire::test(Groups::class)->call('updateMemberRole', 0, $this->tenant->id, 'member');

    $granted = AuditEvent::query()->where('event', 'platform.admin.granted')->sole();
    $revoked = AuditEvent::query()->where('event', 'platform.admin.revoked')->sole();

    expect($granted->metadata)->toMatchArray(['previous_role' => 'member', 'new_role' => 'admin', 'target_user_id' => $this->tenant->id])
        ->and($revoked->metadata)->toMatchArray(['previous_role' => 'admin', 'new_role' => 'member']);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'Security alert:')
            && ($context['type'] ?? null) === 'platform_admin_changed')
        ->twice();
});

test('role changes in other teams do not record platform admin events', function () {
    $member = User::factory()->create();
    $this->tenantTeam->members()->attach($member->id, ['role' => 'member']);
    actAsPlatformAccountManager($this->platformAdmin, $this->rootTeam);

    Livewire::test(Groups::class)->call('updateMemberRole', $this->tenantTeam->id, $member->id, 'admin');

    expect(AuditEvent::query()->where('event', 'like', 'platform.admin.%')->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('event', 'platform.team.member_role_updated')->exists())->toBeTrue();
});
