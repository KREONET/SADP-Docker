<?php

use App\Models\StandaloneDocker;
use App\Models\User;
use App\Policies\StandaloneDockerPolicy;

it('allows any user to view any standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new StandaloneDockerPolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view their team standalone docker', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->view($user, $standaloneDocker))->toBeTrue();
});

it('denies user from viewing another team standalone docker', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 2]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->view($user, $standaloneDocker))->toBeFalse();
});

it('allows platform admin to create standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(true);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $policy = new StandaloneDockerPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies non platform admin from creating standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(false);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $policy = new StandaloneDockerPolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows platform admin to update standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(true);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->update($user, $standaloneDocker))->toBeTrue();
});

it('denies non platform admin from updating standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(false);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->update($user, $standaloneDocker))->toBeFalse();
});

it('allows platform admin to delete standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(true);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->delete($user, $standaloneDocker))->toBeTrue();
});

it('denies non platform admin from deleting standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(false);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->delete($user, $standaloneDocker))->toBeFalse();
});

it('denies restore for standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->restore($user, $standaloneDocker))->toBeFalse();
});

it('denies force delete for standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->forceDelete($user, $standaloneDocker))->toBeFalse();
});

it('denies a team owner who is not a platform admin from changing their team standalone docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);
    $user->shouldReceive('isInstanceAdmin')->andReturn(false);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->create($user))->toBeFalse();
    expect($policy->update($user, $standaloneDocker))->toBeFalse();
    expect($policy->delete($user, $standaloneDocker))->toBeFalse();
});

it('denies a platform admin from changing a StandaloneDocker of a team they are not in', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(true);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $standaloneDocker = Mockery::mock(StandaloneDocker::class)->makePartial();
    $standaloneDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 2]);

    $policy = new StandaloneDockerPolicy;
    expect($policy->update($user, $standaloneDocker))->toBeFalse();
    expect($policy->delete($user, $standaloneDocker))->toBeFalse();
});
