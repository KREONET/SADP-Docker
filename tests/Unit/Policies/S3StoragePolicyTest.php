<?php

use App\Models\S3Storage;
use App\Models\Team;
use App\Models\User;
use App\Policies\S3StoragePolicy;

it('allows the creator to view, update, delete and validate their own S3 storage', function () {
    $user = mockS3PolicyUser(id: 5, teamIds: [1], isPlatformAdmin: false);
    $storage = mockS3PolicyStorage(teamId: 1, userId: 5);

    $policy = new S3StoragePolicy;
    expect($policy->view($user, $storage))->toBeTrue();
    expect($policy->update($user, $storage))->toBeTrue();
    expect($policy->delete($user, $storage))->toBeTrue();
    expect($policy->validateConnection($user, $storage))->toBeTrue();
});

it('denies a user access to an S3 storage another team member created', function () {
    $user = mockS3PolicyUser(id: 5, teamIds: [1], isPlatformAdmin: false);
    $storage = mockS3PolicyStorage(teamId: 1, userId: 6);

    $policy = new S3StoragePolicy;
    expect($policy->view($user, $storage))->toBeFalse();
    expect($policy->update($user, $storage))->toBeFalse();
    expect($policy->delete($user, $storage))->toBeFalse();
    expect($policy->validateConnection($user, $storage))->toBeFalse();
});

it('denies a team owner access to an S3 storage another team member created', function () {
    $user = mockS3PolicyUser(id: 5, teamIds: [1], isPlatformAdmin: false);
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);
    $storage = mockS3PolicyStorage(teamId: 1, userId: 6);

    $policy = new S3StoragePolicy;
    expect($policy->view($user, $storage))->toBeFalse();
    expect($policy->update($user, $storage))->toBeFalse();
});

it('denies a non platform admin access to an S3 storage without a creator', function () {
    $user = mockS3PolicyUser(id: 5, teamIds: [1], isPlatformAdmin: false);
    $storage = mockS3PolicyStorage(teamId: 1, userId: null);

    $policy = new S3StoragePolicy;
    expect($policy->view($user, $storage))->toBeFalse();
    expect($policy->update($user, $storage))->toBeFalse();
});

it('allows a platform admin to manage every S3 storage of their team', function () {
    $user = mockS3PolicyUser(id: 5, teamIds: [1], isPlatformAdmin: true);

    $policy = new S3StoragePolicy;
    foreach ([6, null] as $creatorId) {
        $storage = mockS3PolicyStorage(teamId: 1, userId: $creatorId);
        expect($policy->view($user, $storage))->toBeTrue();
        expect($policy->update($user, $storage))->toBeTrue();
        expect($policy->delete($user, $storage))->toBeTrue();
        expect($policy->validateConnection($user, $storage))->toBeTrue();
    }
});

it('denies access to an S3 storage from another team, even for its creator or a platform admin', function () {
    $policy = new S3StoragePolicy;

    $creator = mockS3PolicyUser(id: 5, teamIds: [1], isPlatformAdmin: false);
    $platformAdmin = mockS3PolicyUser(id: 7, teamIds: [1], isPlatformAdmin: true);
    $storage = mockS3PolicyStorage(teamId: 2, userId: 5);

    expect($policy->view($creator, $storage))->toBeFalse();
    expect($policy->update($creator, $storage))->toBeFalse();
    expect($policy->view($platformAdmin, $storage))->toBeFalse();
    expect($policy->delete($platformAdmin, $storage))->toBeFalse();
});

it('allows any user with a current team to create S3 storage', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('currentTeam')->andReturn(new Team);
    $user->shouldReceive('isAdmin')->andReturn(false);

    $policy = new S3StoragePolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies creating S3 storage without a current team', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('currentTeam')->andReturn(null);

    $policy = new S3StoragePolicy;
    expect($policy->create($user))->toBeFalse();
});

it('denies restore and force delete of S3 storage', function () {
    $user = mockS3PolicyUser(id: 5, teamIds: [1], isPlatformAdmin: true);
    $storage = mockS3PolicyStorage(teamId: 1, userId: 5);

    $policy = new S3StoragePolicy;
    expect($policy->restore($user, $storage))->toBeFalse();
    expect($policy->forceDelete($user, $storage))->toBeFalse();
});

/**
 * @param  array<int, int>  $teamIds
 */
function mockS3PolicyUser(int $id, array $teamIds, bool $isPlatformAdmin): User
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('id')->andReturn($id);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(
        collect($teamIds)->map(fn (int $teamId) => (object) ['id' => $teamId])
    );
    $user->shouldReceive('isInstanceAdmin')->andReturn($isPlatformAdmin);

    return $user;
}

function mockS3PolicyStorage(int $teamId, ?int $userId): S3Storage
{
    $storage = Mockery::mock(S3Storage::class)->makePartial();
    $storage->shouldReceive('getAttribute')->with('team_id')->andReturn($teamId);
    $storage->shouldReceive('getAttribute')->with('user_id')->andReturn($userId);

    return $storage;
}
