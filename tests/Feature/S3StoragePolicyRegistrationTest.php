<?php

use App\Models\S3Storage;
use App\Models\Team;
use App\Models\User;
use App\Policies\S3StoragePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

test('s3 storage model resolves its registered policy through the gate', function () {
    expect(Gate::getPolicyFor(S3Storage::class))->toBeInstanceOf(S3StoragePolicy::class);
});

test('s3 storage create ability allows any user with a current team', function () {
    $team = Team::factory()->create();

    $admin = User::factory()->create();
    $admin->teams()->attach($team, ['role' => 'admin']);

    $member = User::factory()->create();
    $member->teams()->attach($team, ['role' => 'member']);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    expect($admin->can('create', S3Storage::class))->toBeTrue()
        ->and($member->can('create', S3Storage::class))->toBeTrue();
});

test('s3 storage validate connection ability is limited to the creator and platform admins', function () {
    $team = Team::factory()->create();

    $creator = User::factory()->create();
    $creator->teams()->attach($team, ['role' => 'member']);

    $teamAdmin = User::factory()->create();
    $teamAdmin->teams()->attach($team, ['role' => 'admin']);

    $platformAdmin = User::factory()->create();
    $platformAdmin->teams()->attach($team, ['role' => 'member']);
    makePlatformAdmin($platformAdmin);

    $storage = S3Storage::create([
        'team_id' => $team->id,
        'user_id' => $creator->id,
        'name' => 'Backups',
        'description' => 'Team backup storage',
        'region' => 'us-east-1',
        'key' => 'access-key',
        'secret' => 'secret-key',
        'bucket' => 'coolify-backups',
        'endpoint' => 'https://s3.us-east-1.amazonaws.com',
    ]);

    expect($creator->can('validateConnection', $storage))->toBeTrue()
        ->and($platformAdmin->can('validateConnection', $storage))->toBeTrue()
        ->and($teamAdmin->can('validateConnection', $storage))->toBeFalse();
});
