<?php

use App\Models\SharedEnvironmentVariable;
use App\Models\User;
use App\Policies\SharedEnvironmentVariablePolicy;

it('allows any user to view any shared environment variables', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view their team shared environment variable', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $model = mockSharedEnvironmentVariable(teamId: 1);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->view($user, $model))->toBeTrue();
});

it('denies non-team member to view shared environment variable', function () {
    $teams = collect([
        (object) ['id' => 1, 'pivot' => (object) ['role' => 'member']],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $model = mockSharedEnvironmentVariable(teamId: 2);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->view($user, $model))->toBeFalse();
});

it('allows platform admin to create shared environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(true);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies non platform admin to create shared environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(false);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows platform admin to update shared environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(true);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $model = mockSharedEnvironmentVariable(teamId: 1);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->update($user, $model))->toBeTrue();
});

it('denies non platform admin to update shared environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(false);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $model = mockSharedEnvironmentVariable(teamId: 1);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->update($user, $model))->toBeFalse();
});

it('allows platform admin to delete shared environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(true);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $model = mockSharedEnvironmentVariable(teamId: 1);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->delete($user, $model))->toBeTrue();
});

it('denies non platform admin to delete shared environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(false);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $model = mockSharedEnvironmentVariable(teamId: 1);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->delete($user, $model))->toBeFalse();
});

it('denies restore of shared environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $model = mockSharedEnvironmentVariable(teamId: 1);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->restore($user, $model))->toBeFalse();
});

it('denies force delete of shared environment variable', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $model = mockSharedEnvironmentVariable(teamId: 1);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->forceDelete($user, $model))->toBeFalse();
});

it('allows platform admin to manage environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(true);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $model = mockSharedEnvironmentVariable(teamId: 1);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->manageEnvironment($user, $model))->toBeTrue();
});

it('denies non platform admin to manage environment', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(false);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $model = mockSharedEnvironmentVariable(teamId: 1);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->manageEnvironment($user, $model))->toBeFalse();
});

it('denies a team owner who is not a platform admin from changing shared variables', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);
    $user->shouldReceive('isInstanceAdmin')->andReturn(false);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $model = mockSharedEnvironmentVariable(teamId: 1);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->create($user))->toBeFalse();
    expect($policy->update($user, $model))->toBeFalse();
    expect($policy->delete($user, $model))->toBeFalse();
});

it('denies a platform admin from changing shared variables of a team they are not in', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(true);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $model = mockSharedEnvironmentVariable(teamId: 2);

    $policy = new SharedEnvironmentVariablePolicy;
    expect($policy->update($user, $model))->toBeFalse();
    expect($policy->delete($user, $model))->toBeFalse();
});

function mockSharedEnvironmentVariable(int $teamId): SharedEnvironmentVariable
{
    $sharedEnvironmentVariable = Mockery::mock(SharedEnvironmentVariable::class)->makePartial();
    $sharedEnvironmentVariable->team_id = $teamId;

    return $sharedEnvironmentVariable;
}
