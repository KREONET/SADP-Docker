<?php

use App\Livewire\SharedVariables\Team\Index as TeamSharedVariablesIndex;
use App\Livewire\Storage\Index as StorageIndex;
use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::query()->whereKey(0)->delete();
    $settings = new InstanceSettings(['is_api_enabled' => true]);
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->team = Team::factory()->create();

    // A team owner is still a regular user: only platform admins manage shared infrastructure.
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);

    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member->id, ['role' => 'member']);

    $this->platformAdmin = User::factory()->create();
    $this->team->members()->attach($this->platformAdmin->id, ['role' => 'admin']);
    makePlatformAdmin($this->platformAdmin);
});

function actAsInfrastructureUser(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

function createInfrastructureS3Storage(Team $team, ?User $creator, string $name): S3Storage
{
    return S3Storage::create([
        'team_id' => $team->id,
        'user_id' => $creator?->id,
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'access-key',
        'secret' => 'secret-key',
        'bucket' => 'backups',
        'endpoint' => 'https://s3.amazonaws.com',
        'is_usable' => true,
    ]);
}

describe('servers, destinations and shared variables', function () {
    it('lets only platform admins create, update and delete them', function () {
        $server = Server::factory()->create(['team_id' => $this->team->id]);
        $destination = StandaloneDocker::query()->where('server_id', $server->id)->first()
            ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
        $variable = SharedEnvironmentVariable::create([
            'key' => 'API_URL',
            'value' => 'https://example.com',
            'type' => 'team',
            'team_id' => $this->team->id,
        ]);

        foreach ([$this->owner, $this->member] as $user) {
            expect($user->can('create', Server::class))->toBeFalse()
                ->and($user->can('update', $server))->toBeFalse()
                ->and($user->can('delete', $server))->toBeFalse()
                ->and($user->can('create', StandaloneDocker::class))->toBeFalse()
                ->and($user->can('create', SwarmDocker::class))->toBeFalse()
                ->and($user->can('update', $destination))->toBeFalse()
                ->and($user->can('delete', $destination))->toBeFalse()
                ->and($user->can('create', SharedEnvironmentVariable::class))->toBeFalse()
                ->and($user->can('update', $variable))->toBeFalse()
                ->and($user->can('delete', $variable))->toBeFalse();

            // Viewing stays available to every team member.
            expect($user->can('view', $server))->toBeTrue()
                ->and($user->can('view', $destination))->toBeTrue()
                ->and($user->can('view', $variable))->toBeTrue();
        }

        $admin = $this->platformAdmin->fresh();
        expect($admin->can('create', Server::class))->toBeTrue()
            ->and($admin->can('update', $server))->toBeTrue()
            ->and($admin->can('delete', $server))->toBeTrue()
            ->and($admin->can('create', StandaloneDocker::class))->toBeTrue()
            ->and($admin->can('update', $destination))->toBeTrue()
            ->and($admin->can('create', SharedEnvironmentVariable::class))->toBeTrue()
            ->and($admin->can('update', $variable))->toBeTrue()
            ->and($admin->can('delete', $variable))->toBeTrue();
    });

    it('forbids a team owner from opening the new server page', function () {
        actAsInfrastructureUser($this->owner, $this->team);

        $this->get(route('server.create'))->assertForbidden();
    });

    it('stops a team owner from adding a team shared variable', function () {
        actAsInfrastructureUser($this->owner, $this->team);

        Livewire::test(TeamSharedVariablesIndex::class)
            ->call('saveKey', ['key' => 'OWNER_VAR', 'value' => 'x', 'is_multiline' => false, 'is_literal' => false]);

        expect(SharedEnvironmentVariable::query()->where('key', 'OWNER_VAR')->exists())->toBeFalse();
    });

    it('lets a platform admin add a team shared variable', function () {
        actAsInfrastructureUser($this->platformAdmin, $this->team);

        Livewire::test(TeamSharedVariablesIndex::class)
            ->call('saveKey', ['key' => 'ADMIN_VAR', 'value' => 'x', 'is_multiline' => false, 'is_literal' => false]);

        expect(SharedEnvironmentVariable::query()->where('key', 'ADMIN_VAR')->where('team_id', $this->team->id)->exists())->toBeTrue();
    });
});

describe('S3 storages', function () {
    it('lets every team user create their own storage', function () {
        actAsInfrastructureUser($this->member, $this->team);

        expect($this->member->can('create', S3Storage::class))->toBeTrue()
            ->and($this->owner->can('create', S3Storage::class))->toBeTrue();
    });

    it('lists only the storages the user created', function () {
        createInfrastructureS3Storage($this->team, $this->member, 'member-bucket');
        createInfrastructureS3Storage($this->team, $this->owner, 'owner-bucket');
        createInfrastructureS3Storage($this->team, null, 'legacy-bucket');

        actAsInfrastructureUser($this->member, $this->team);

        Livewire::test(StorageIndex::class)
            ->assertSee('member-bucket')
            ->assertDontSee('owner-bucket')
            ->assertDontSee('legacy-bucket');

        expect(S3Storage::ownedByCurrentTeam()->pluck('name')->all())->toBe(['member-bucket'])
            ->and(currentTeam()->s3s()->visibleTo($this->member)->pluck('name')->all())->toBe(['member-bucket']);
    });

    it('lets a platform admin see every storage of the team', function () {
        createInfrastructureS3Storage($this->team, $this->member, 'member-bucket');
        createInfrastructureS3Storage($this->team, $this->owner, 'owner-bucket');
        createInfrastructureS3Storage($this->team, null, 'legacy-bucket');

        actAsInfrastructureUser($this->platformAdmin, $this->team);

        Livewire::test(StorageIndex::class)
            ->assertSee('member-bucket')
            ->assertSee('owner-bucket')
            ->assertSee('legacy-bucket');
    });

    it('hides another user\'s storage page', function () {
        $ownerStorage = createInfrastructureS3Storage($this->team, $this->owner, 'owner-bucket');

        actAsInfrastructureUser($this->member, $this->team);

        $this->get(route('storage.show', ['storage_uuid' => $ownerStorage->uuid]))->assertNotFound();
        expect($this->member->can('view', $ownerStorage))->toBeFalse()
            ->and($this->member->can('update', $ownerStorage))->toBeFalse()
            ->and($this->member->can('delete', $ownerStorage))->toBeFalse();
    });

    it('lists and shows only the caller\'s storages through the API', function () {
        $ownerStorage = createInfrastructureS3Storage($this->team, $this->owner, 'owner-bucket');
        $memberStorage = createInfrastructureS3Storage($this->team, $this->member, 'member-bucket');

        session(['currentTeam' => $this->team]);
        $token = $this->owner->createToken('owner-token', ['*'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->withHeaders($headers)->getJson('/api/v1/s3-storages')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.uuid', $ownerStorage->uuid);

        $this->withHeaders($headers)->getJson("/api/v1/s3-storages/{$memberStorage->uuid}")->assertNotFound();
        $this->withHeaders($headers)->deleteJson("/api/v1/s3-storages/{$memberStorage->uuid}")->assertNotFound();
        expect(S3Storage::query()->whereKey($memberStorage->id)->exists())->toBeTrue();
    });

    it('records the creator of a storage created through the API', function () {
        session(['currentTeam' => $this->team]);
        $token = $this->owner->createToken('owner-token', ['*'])->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/s3-storages', [
            'name' => 'owner-api-bucket',
            'endpoint' => 'https://s3.amazonaws.com',
            'bucket' => 'coolify-backups',
            'region' => 'us-east-1',
            'key' => 'AKIAEXAMPLEKEY',
            'secret' => 'example-secret-value',
        ])->assertCreated();

        $this->assertDatabaseHas('s3_storages', [
            'name' => 'owner-api-bucket',
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
        ]);
    });

    it('does not show storages from another team, even to their creator', function () {
        $otherTeam = Team::factory()->create();
        $otherTeam->members()->attach($this->member->id, ['role' => 'member']);
        createInfrastructureS3Storage($otherTeam, $this->member, 'other-team-bucket');

        actAsInfrastructureUser($this->member, $this->team);

        expect(S3Storage::ownedByCurrentTeam()->pluck('name')->all())->toBe([]);
    });
});
