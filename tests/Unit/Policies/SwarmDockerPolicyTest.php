<?php

use App\Models\SwarmDocker;
use App\Models\User;
use App\Policies\SwarmDockerPolicy;

it('allows any user to view any swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $policy = new SwarmDockerPolicy;
    expect($policy->viewAny($user))->toBeTrue();
});

it('allows team member to view their team swarm docker', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new SwarmDockerPolicy;
    expect($policy->view($user, $swarmDocker))->toBeTrue();
});

it('denies user from viewing another team swarm docker', function () {
    $teams = collect([
        (object) ['id' => 1],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 2]);

    $policy = new SwarmDockerPolicy;
    expect($policy->view($user, $swarmDocker))->toBeFalse();
});

it('allows platform admin to create swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(true);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $policy = new SwarmDockerPolicy;
    expect($policy->create($user))->toBeTrue();
});

it('denies non platform admin from creating swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(false);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $policy = new SwarmDockerPolicy;
    expect($policy->create($user))->toBeFalse();
});

it('allows platform admin to update swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(true);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new SwarmDockerPolicy;
    expect($policy->update($user, $swarmDocker))->toBeTrue();
});

it('denies non platform admin from updating swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(false);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new SwarmDockerPolicy;
    expect($policy->update($user, $swarmDocker))->toBeFalse();
});

it('allows platform admin to delete swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(true);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new SwarmDockerPolicy;
    expect($policy->delete($user, $swarmDocker))->toBeTrue();
});

it('denies non platform admin from deleting swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(false);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new SwarmDockerPolicy;
    expect($policy->delete($user, $swarmDocker))->toBeFalse();
});

it('denies restore for swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new SwarmDockerPolicy;
    expect($policy->restore($user, $swarmDocker))->toBeFalse();
});

it('denies force delete for swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new SwarmDockerPolicy;
    expect($policy->forceDelete($user, $swarmDocker))->toBeFalse();
});

it('denies a team owner who is not a platform admin from changing their team swarm docker', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isAdmin')->andReturn(true);
    $user->shouldReceive('isAdminOfTeam')->with(1)->andReturn(true);
    $user->shouldReceive('isInstanceAdmin')->andReturn(false);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 1]);

    $policy = new SwarmDockerPolicy;
    expect($policy->create($user))->toBeFalse();
    expect($policy->update($user, $swarmDocker))->toBeFalse();
    expect($policy->delete($user, $swarmDocker))->toBeFalse();
});

it('denies a platform admin from changing a SwarmDocker of a team they are not in', function () {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('isInstanceAdmin')->andReturn(true);
    $user->shouldReceive('getAttribute')->with('teams')->andReturn(collect([(object) ['id' => 1]]));

    $swarmDocker = Mockery::mock(SwarmDocker::class)->makePartial();
    $swarmDocker->shouldReceive('getAttribute')->with('server')->andReturn((object) ['team_id' => 2]);

    $policy = new SwarmDockerPolicy;
    expect($policy->update($user, $swarmDocker))->toBeFalse();
    expect($policy->delete($user, $swarmDocker))->toBeFalse();
});
