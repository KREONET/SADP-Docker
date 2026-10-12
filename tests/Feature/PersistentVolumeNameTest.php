<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Support\PersistentVolumeName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

const SHARED_VOLUME_COMPOSE = <<<'YAML'
services:
  app:
    image: nginx:latest
    volumes:
      - app-data:/data
      - uploads:/uploads
  worker:
    image: nginx:latest
    volumes:
      - app-data:/data
YAML;

beforeEach(function () {
    Bus::fake();
    Server::flushIdentityMap();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['email' => 'inmd1@kisti.re.kr']);
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'My Shop']);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

describe('generated names', function () {
    it('combines the email nickname, a random part and the project name', function () {
        $name = PersistentVolumeName::generate($this->project, 'postgres-data', $this->user);

        expect($name)->toMatch('/^inmd1-[a-z0-9]{8}-my-shop_postgres-data$/')
            ->and(PersistentVolumeName::isGenerated($name))->toBeTrue()
            ->and(PersistentVolumeName::generate($this->project, 'postgres-data', $this->user))->not->toBe($name);
    });

    it('falls back when the project name has no latin letters', function () {
        $this->project->name = '테스트 프로젝트';

        expect(PersistentVolumeName::generate($this->project, 'data', $this->user))->toMatch('/^inmd1-[a-z0-9]{8}-project_data$/');
    });

    it('uses the team owner when nobody is signed in', function () {
        expect(PersistentVolumeName::generate($this->project, 'data'))->toStartWith('inmd1-');
    });

    it('does not take legacy names for generated ones', function (string $name) {
        expect(PersistentVolumeName::isGenerated($name))->toBeFalse();
    })->with([
        'database' => 'postgres-data-'.str_repeat('a', 24),
        'compose' => str_repeat('a', 24).'_app-data',
        'user storage' => str_repeat('a', 24).'-my_data',
    ]);

    it('finds the stored compose volume by its purpose', function () {
        $uuid = str_repeat('a', 24);
        $generated = PersistentVolumeName::generate($this->project, 'app-data', $this->user);

        expect(PersistentVolumeName::findComposeVolume(["{$uuid}_app-data"], 'app-data', $uuid))->toBe("{$uuid}_app-data")
            ->and(PersistentVolumeName::findComposeVolume([$generated.'-pr-3'], 'app-data', $uuid))->toBe($generated)
            ->and(PersistentVolumeName::findComposeVolume([$generated], 'uploads', $uuid))->toBeNull()
            ->and(PersistentVolumeName::findComposeVolume(["{$uuid}-my_app-data"], 'app-data', $uuid))->toBeNull();
    });
});

it('names the volume of a new database after the creator and project', function () {
    $this->actingAs($this->user);

    $database = StandalonePostgresql::create([
        'name' => 'db',
        'postgres_password' => 'secret',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    expect($database->persistentStorages()->pluck('name')->all())
        ->toHaveCount(1)
        ->each->toMatch('/^inmd1-[a-z0-9]{8}-my-shop_postgres-data$/');
});

it('keeps compose volume names across deployments and shares them between containers', function () {
    $this->actingAs($this->user);
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => SHARED_VOLUME_COMPOSE,
    ]);

    $service->parse();
    $firstNames = LocalPersistentVolume::query()->orderBy('id')->pluck('name', 'id')->all();

    $service->refresh()->parse();

    $names = collect($firstNames)->values();
    expect(LocalPersistentVolume::query()->orderBy('id')->pluck('name', 'id')->all())->toBe($firstNames)
        ->and($names->filter(fn (string $name) => str_ends_with($name, '_app-data'))->unique())->toHaveCount(1)
        ->and($names->filter(fn (string $name) => str_ends_with($name, '_uploads')))->toHaveCount(1)
        ->and($names->every(fn (string $name) => PersistentVolumeName::isGenerated($name)))->toBeTrue();
});

it('keeps the legacy name of an existing compose volume', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:latest\n    volumes:\n      - app-data:/data\n",
    ]);
    LocalPersistentVolume::create([
        'name' => "{$application->uuid}_app-data",
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => Application::class,
    ]);

    $application->parse();

    expect($application->persistentStorages()->pluck('name')->all())->toBe(["{$application->uuid}_app-data"]);
});

describe('public database address', function () {
    it('uses the wildcard domain of the server', function () {
        $this->server->settings()->update(['wildcard_domain' => 'https://apps.example.com']);

        expect(publicDatabaseHost($this->server->refresh(), 'abc123'))->toBe('abc123.apps.example.com');
    });

    it('uses sslip without a wildcard domain', function () {
        $this->server->forceFill(['ip' => '203.0.113.7'])->saveQuietly();
        $this->server->settings()->update(['wildcard_domain' => null]);

        expect(publicDatabaseHost($this->server->refresh(), 'abc123'))->toBe('abc123.203.0.113.7.sslip.io');
    });

    it('uses the hostname of a server added by hostname', function () {
        $this->server->forceFill(['ip' => 'DB.Example.com'])->saveQuietly();
        $this->server->settings()->update(['wildcard_domain' => null]);

        expect(publicDatabaseHost($this->server->refresh(), 'abc123'))->toBe('db.example.com');
    });

    it('puts the domain in the external database url', function () {
        $this->server->settings()->update(['wildcard_domain' => 'https://apps.example.com']);
        $database = StandalonePostgresql::create([
            'name' => 'db',
            'postgres_user' => 'postgres',
            'postgres_password' => 'secret',
            'postgres_db' => 'app',
            'is_public' => true,
            'public_port' => 54321,
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        expect($database->refresh()->external_db_url)->toContain("@{$database->uuid}.apps.example.com:54321/");
    });
});
