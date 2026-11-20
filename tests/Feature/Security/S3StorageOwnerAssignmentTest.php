<?php

use App\Livewire\Storage\Owner;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\Support\TwoFactor;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->team = Team::factory()->create(['show_boarding' => false]);
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member->id, ['role' => 'member']);

    $this->platformAdmin = TwoFactor::confirm(User::factory()->create());
    $this->team->members()->attach($this->platformAdmin->id, ['role' => 'admin']);
    makePlatformAdmin($this->platformAdmin);

    $this->outsider = User::factory()->create();
    Team::factory()->create()->members()->attach($this->outsider->id, ['role' => 'owner']);

    $this->storage = S3Storage::create([
        'team_id' => $this->team->id,
        'user_id' => null,
        'name' => 'legacy-bucket',
        'region' => 'us-east-1',
        'key' => 'access-key',
        'secret' => 'secret-key',
        'bucket' => 'backups',
        'endpoint' => 'https://s3.amazonaws.com',
        'is_usable' => true,
    ]);
});

function actAsStorageOwnerUser(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

test('a legacy storage without an owner is visible to platform admins only', function () {
    expect(S3Storage::query()->visibleTo($this->member)->exists())->toBeFalse()
        ->and(S3Storage::query()->visibleTo($this->platformAdmin)->exists())->toBeTrue();
});

test('a platform admin assigns a team member as owner after confirming the password', function () {
    actAsStorageOwnerUser($this->platformAdmin, $this->team);

    Livewire::test(Owner::class, ['storage' => $this->storage])
        ->assertSee('No owner')
        ->set('selectedUserId', $this->member->id)
        ->call('assignOwner', 'password')
        ->assertReturned(true)
        ->assertDispatched('success');

    expect($this->storage->fresh()->user_id)->toBe($this->member->id)
        ->and(S3Storage::query()->visibleTo($this->member)->pluck('id')->all())->toBe([$this->storage->id])
        ->and(AuditEvent::query()->where('event', 's3_storage.owner.assigned')->where('result', 'success')->exists())->toBeTrue();
});

test('a wrong password does not change the owner', function () {
    actAsStorageOwnerUser($this->platformAdmin, $this->team);

    Livewire::test(Owner::class, ['storage' => $this->storage])
        ->set('selectedUserId', $this->member->id)
        ->call('assignOwner', 'wrong-password')
        ->assertReturned('The provided password is incorrect.');

    expect($this->storage->fresh()->user_id)->toBeNull()
        ->and(AuditEvent::query()->where('event', 's3_storage.owner.assigned')->where('result', 'denied')->exists())->toBeTrue();
});

test('only members of the storage team can become the owner', function () {
    actAsStorageOwnerUser($this->platformAdmin, $this->team);

    Livewire::test(Owner::class, ['storage' => $this->storage])
        ->set('selectedUserId', $this->outsider->id)
        ->call('assignOwner', 'password')
        ->assertReturned('Choose a member of the storage team.');

    expect($this->storage->fresh()->user_id)->toBeNull();
});

test('users who are not platform admins cannot assign an owner', function () {
    actAsStorageOwnerUser($this->member, $this->team);

    Livewire::test(Owner::class, ['storage' => $this->storage])->assertForbidden();
});

test('the storage page shows the owner section to platform admins only', function () {
    $this->storage->forceFill(['user_id' => $this->member->id])->save();

    actAsStorageOwnerUser($this->platformAdmin, $this->team);
    $this->get(route('storage.show', ['storage_uuid' => $this->storage->uuid]))
        ->assertOk()
        ->assertSee('Current owner: '.$this->member->email);

    actAsStorageOwnerUser($this->member, $this->team);
    $this->get(route('storage.show', ['storage_uuid' => $this->storage->uuid]))
        ->assertOk()
        ->assertDontSee('Change the storage owner?');
});
