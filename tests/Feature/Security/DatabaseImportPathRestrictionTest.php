<?php

use App\Actions\Database\StartDatabaseImport;
use App\Jobs\CoolifyTask;
use App\Livewire\Project\Database\ImportForm;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Support\DatabaseImport\DatabaseImportException;
use App\Support\DatabaseImport\DatabaseImportSource;
use App\Support\DatabaseImport\ServerImportPath;
use App\Support\RemoteProcessCommand;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('constants.ssh.mux_enabled', false);
    config()->set('database-import.allowed_paths', []);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'user' => 'root',
    ]);
    $this->destination = StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'docker']
    );
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'db',
        'image' => 'postgres:17',
        'status' => 'running',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Queue::fake();
});

function encryptedExecutionForImportTest(Team $team, StandalonePostgresql $database, string $filename): ScheduledDatabaseBackupExecution
{
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'database_type' => $database->getMorphClass(),
        'database_id' => $database->id,
        'team_id' => $team->id,
        'encrypt_backup' => true,
    ]);

    return ScheduledDatabaseBackupExecution::create([
        'uuid' => (string) Str::uuid(),
        'database_name' => 'db',
        'filename' => $filename,
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'success',
        'is_encrypted' => true,
        'encryption_passphrase' => str_repeat('ab', 32),
    ]);
}

/**
 * @param  array<string, mixed>  $fakes
 */
function startServerImportForPathTest(object $test, string $path, string $resolvedPath, array $fakes = []): string
{
    Process::fake([
        '*realpath*' => Process::result($resolvedPath),
        '*stat -c %s*' => Process::result('2048'),
        '*od -An -tx1*' => Process::result(' 2d 2d 20 50 6f 73'),
        ...$fakes,
        '*' => Process::result(''),
    ]);

    $activity = app(StartDatabaseImport::class)->handle(
        $test->database,
        new DatabaseImportSource('server', path: $path),
        $test->team->id,
    );

    return (string) RemoteProcessCommand::read($activity);
}

test('the coolify backups directory is always allowed and extra directories come from config', function () {
    expect(ServerImportPath::allowedDirectories())->toBe(['/data/coolify/backups'])
        ->and(ServerImportPath::isWithinAllowedDirectory('/data/coolify/backups/databases/a.sql'))->toBeTrue()
        ->and(ServerImportPath::isWithinAllowedDirectory('/srv/dumps/a.sql'))->toBeFalse();

    config()->set('database-import.allowed_paths', ['/srv/dumps/', '/', 'relative', '/mnt/../etc', ' /mnt/backups ']);

    expect(ServerImportPath::allowedDirectories())->toBe(['/data/coolify/backups', '/srv/dumps', '/mnt/backups'])
        ->and(ServerImportPath::isWithinAllowedDirectory('/srv/dumps/a.sql'))->toBeTrue()
        ->and(ServerImportPath::isWithinAllowedDirectory('/mnt/backups/nested/a.sql'))->toBeTrue();
});

test('paths outside the allow-list or that try to escape it are rejected', function (string $path) {
    expect(ServerImportPath::isWithinAllowedDirectory($path))->toBeFalse();
})->with([
    'system file' => ['/etc/passwd'],
    'home directory' => ['/home/user/backup.sql.gz'],
    'sibling directory with the same prefix' => ['/data/coolify/backups-evil/a.sql'],
    'the directory itself' => ['/data/coolify/backups'],
    'parent traversal' => ['/data/coolify/backups/../source/.env'],
    'dot segment' => ['/data/coolify/backups/./a.sql'],
    'relative' => ['data/coolify/backups/a.sql'],
]);

test('a server import outside the allow-list is rejected before any remote command runs', function () {
    Process::fake();

    expect(fn () => app(StartDatabaseImport::class)->handle(
        $this->database,
        new DatabaseImportSource('server', path: '/etc/backups/secrets.sql'),
        $this->team->id,
    ))->toThrow(DatabaseImportException::class, 'allowed import directory');

    Process::assertNothingRan();
    Queue::assertNotPushed(CoolifyTask::class);
});

test('a symbolic link inside the allow-list that points outside it is rejected', function () {
    expect(fn () => startServerImportForPathTest($this, '/data/coolify/backups/link.sql', '/etc/shadow.sql'))
        ->toThrow(DatabaseImportException::class, 'allowed import directory');

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'stat -c %s'));
    Queue::assertNotPushed(CoolifyTask::class);
});

test('a missing server file is reported as not found', function () {
    expect(fn () => startServerImportForPathTest($this, '/data/coolify/backups/missing.sql', ''))
        ->toThrow(DatabaseImportException::class, 'not found');
});

test('the import reads the resolved path inside the allow-list', function () {
    $command = startServerImportForPathTest($this, '/data/coolify/backups/latest.sql', '/data/coolify/backups/databases/db/real.sql');

    expect($command)->toContain("docker cp '/data/coolify/backups/databases/db/real.sql' '{$this->database->uuid}:/tmp/restore_");
    Process::assertRan(fn ($process) => str_contains($process->command, "realpath '/data/coolify/backups/latest.sql'"));
    Process::assertRan(fn ($process) => str_contains($process->command, "stat -c %s -- '/data/coolify/backups/databases/db/real.sql'"));
});

test('the api rejects server import paths outside the allow-list', function () {
    session(['currentTeam' => $this->team]);
    $token = $this->user->createToken('test-token', ['*'])->plainTextToken;

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson("/api/v1/databases/{$this->database->uuid}/imports", ['source' => 'server', 'path' => '/etc/passwd.sql'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['path']);

    Queue::assertNotPushed(CoolifyTask::class);
});

test('the import form refuses to check a file outside the allow-list', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    Process::fake();

    Livewire::test(ImportFormPathRestrictionTestComponent::class, ['database' => $this->database, 'server' => $this->server])
        ->set('customLocation', '/root/.ssh/id_rsa.sql')
        ->call('checkFile')
        ->assertDispatched('error')
        ->assertSet('filename', null);

    Process::assertNothingRan();
});

test('the import form refuses a symbolic link that resolves outside the allow-list', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    Process::fake(['*realpath*' => Process::result('/etc/shadow.sql'), '*' => Process::result('-rw-r--r-- 1 root root 1 file')]);

    Livewire::test(ImportFormPathRestrictionTestComponent::class, ['database' => $this->database, 'server' => $this->server])
        ->set('customLocation', '/data/coolify/backups/link.sql')
        ->call('checkFile')
        ->assertDispatched('error')
        ->assertSet('filename', null);

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'ls -l'));
});

test('the import form accepts a file inside the allow-list', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    Process::fake([
        '*realpath*' => Process::result('/data/coolify/backups/databases/db/a.sql'),
        '*' => Process::result('-rw-r--r-- 1 root root 1 a.sql'),
    ]);

    Livewire::test(ImportFormPathRestrictionTestComponent::class, ['database' => $this->database, 'server' => $this->server])
        ->set('customLocation', '/data/coolify/backups/databases/db/a.sql')
        ->call('checkFile')
        ->assertDispatched('success')
        ->assertSet('filename', '/data/coolify/backups/databases/db/a.sql');
});

test('an encrypted server backup of the team is decrypted into a 0600 temporary file before the restore', function () {
    $path = '/data/coolify/backups/databases/team/db/pg-dump-db-1.dmp.enc';
    $execution = encryptedExecutionForImportTest($this->team, $this->database, $path);

    $command = startServerImportForPathTest($this, $path, $path);

    expect($command)
        ->toContain("install -m 600 /dev/null '/tmp/database-import-")
        ->toContain("echo '{$execution->encryption_passphrase}' | openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -salt -md sha256 -in '{$path}' -out '/tmp/database-import-")
        ->toContain("docker cp '/tmp/database-import-")
        ->toContain("'backup-decompress-")
        ->toContain("rm -f '/tmp/database-import-")
        ->not->toContain("docker cp '{$path}'");

    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'od -An -tx1'));
    Queue::assertPushed(CoolifyTask::class, fn (CoolifyTask $job) => str_starts_with((string) ($job->call_event_data['serverTmpPath'] ?? ''), '/tmp/database-import-'));
});

test('an encrypted backup of another team cannot be decrypted', function () {
    $otherTeam = Team::factory()->create();
    $path = '/data/coolify/backups/databases/other/db/pg-dump-db-1.dmp.enc';
    encryptedExecutionForImportTest($otherTeam, $this->database, $path);

    expect(fn () => startServerImportForPathTest($this, $path, $path))
        ->toThrow(DatabaseImportException::class, 'cannot be decrypted');

    Queue::assertNotPushed(CoolifyTask::class);
});

test('an encrypted s3 backup is decrypted on the server and streamed from the s3 helper', function () {
    $this->actingAs($this->user);
    $key = 'data/coolify/backups/databases/team/db/pg-dump-db-1.dmp.enc';
    $execution = encryptedExecutionForImportTest($this->team, $this->database, '/'.$key);
    $storage = S3Storage::create([
        'name' => 'Import S3',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://8.8.8.8',
        'is_usable' => true,
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->once()->with($key)->andReturn(true);
    $disk->shouldReceive('size')->once()->with($key)->andReturn(1024);
    $filesystem = Mockery::mock(FilesystemManager::class, [app()])->makePartial();
    $filesystem->shouldReceive('build')->once()->andReturn($disk);
    Storage::swap($filesystem);
    Process::fake();

    $activity = app(StartDatabaseImport::class)->handle(
        $this->database,
        new DatabaseImportSource('s3', path: '/'.$key, s3StorageUuid: $storage->uuid),
        $this->team->id,
    );
    $command = (string) RemoteProcessCommand::read($activity);

    expect($command)
        ->toMatch("#docker cp 's3-restore-[^:]+:/tmp/restore' '/tmp/database-import-[^']+\\.enc'#")
        ->toContain("echo '{$execution->encryption_passphrase}' | openssl enc -d -aes-256-cbc")
        ->toMatch("#docker cp '/tmp/database-import-[a-f0-9-]+' 's3-restore-[^:]+:/tmp/restore'#")
        ->toContain('cat /tmp/restore.prepared');

    Queue::assertPushed(CoolifyTask::class, fn (CoolifyTask $job) => str_ends_with((string) ($job->call_event_data['credentialTmpPath'] ?? ''), '.enc')
        && str_starts_with((string) ($job->call_event_data['serverTmpPath'] ?? ''), '/tmp/database-import-'));
});

class ImportFormPathRestrictionTestComponent extends ImportForm
{
    public function mount($database = null, $server = null): void
    {
        $this->resourceId = $database->id;
        $this->resourceType = $database::class;
        $this->serverId = $server->id;
        $this->container = $database->uuid;
        $this->resourceUuid = $database->uuid;
        $this->resourceStatus = $database->status ?? '';
        $this->resourceDbType = $database->type();
        $this->loadAvailableS3Storages();
    }

    public function render()
    {
        return view('livewire.project.database.import-form');
    }
}
