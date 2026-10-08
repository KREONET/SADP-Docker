<?php

use App\Jobs\DatabaseBackupJob;
use App\Livewire\Project\Database\BackupEdit;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Support\BackupEncryption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('constants.ssh.mux_enabled', false);
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
    $this->backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'database_type' => $this->database->getMorphClass(),
        'database_id' => $this->database->id,
        'team_id' => $this->team->id,
        'encrypt_backup' => true,
    ]);
});

/**
 * Runs the encryption step of the backup job against faked SSH commands.
 */
function runBackupEncryptionStep(object $test, Server $server, string $opensslPath = '/usr/bin/openssl'): array
{
    $location = '/data/coolify/backups/databases/team-1/db/pg-dump-db-1700000000.dmp';
    $execution = ScheduledDatabaseBackupExecution::create([
        'uuid' => (string) Str::uuid(),
        'database_name' => 'db',
        'filename' => $location,
        'scheduled_database_backup_id' => $test->backup->id,
    ]);
    Process::fake([
        '*command -v openssl*' => Process::result($opensslPath),
        '*' => Process::result(''),
    ]);

    $job = new DatabaseBackupJob($test->backup);
    $job->server = $server;
    $job->backup_location = $location;
    $job->backup_file = '/pg-dump-db-1700000000.dmp';
    $job->backup_log = $execution;

    $exception = null;
    try {
        (new ReflectionMethod($job, 'encryptBackupFile'))->invoke($job);
    } catch (Throwable $e) {
        $exception = $e;
    }

    return [$job, $execution->fresh(), $exception];
}

test('backup encryption is off by default', function () {
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'database_type' => $this->database->getMorphClass(),
        'database_id' => $this->database->id,
        'team_id' => $this->team->id,
    ])->fresh();

    expect($backup->encrypt_backup)->toBeFalse()
        ->and($backup->shouldEncryptBackups())->toBeFalse();
});

test('the coolify instance database backup cannot be encrypted', function () {
    $instanceBackup = new ScheduledDatabaseBackup([
        'database_type' => StandalonePostgresql::class,
        'database_id' => 0,
        'encrypt_backup' => true,
    ]);

    expect($instanceBackup->supportsEncryption())->toBeFalse()
        ->and($instanceBackup->shouldEncryptBackups())->toBeFalse()
        ->and($this->backup->shouldEncryptBackups())->toBeTrue();
});

test('encrypt commands use FIPS approved openssl options and pass the passphrase on stdin only', function () {
    $passphrase = BackupEncryption::generatePassphrase();
    $commands = BackupEncryption::encryptCommands('/data/coolify/backups/a.dmp', '/data/coolify/backups/a.dmp.enc', $passphrase);
    $script = implode("\n", $commands);

    expect($script)
        ->toContain("echo '{$passphrase}' | openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -salt -md sha256 -in '/data/coolify/backups/a.dmp' -out '/data/coolify/backups/a.dmp.enc' -pass stdin")
        ->toContain("rm -f '/data/coolify/backups/a.dmp'")
        ->not->toContain('-pass pass:')
        ->not->toContain(' -k ')
        ->and(substr_count($script, $passphrase))->toBe(1)
        // SSH retry logging keeps the first 100 characters of a command.
        ->and(substr($script, 0, 100))->not->toContain($passphrase)
        ->and(strlen($commands[0]))->toBeGreaterThan(100);
});

test('decrypt commands create the plain file with mode 0600 before openssl writes it', function () {
    $passphrase = BackupEncryption::generatePassphrase();
    $commands = BackupEncryption::decryptCommands('/data/coolify/backups/a.dmp.enc', '/tmp/database-import-x', $passphrase);

    expect($commands[1])->toBe("install -m 600 /dev/null '/tmp/database-import-x'")
        ->and($commands[2])->toBe("echo '{$passphrase}' | openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -salt -md sha256 -in '/data/coolify/backups/a.dmp.enc' -out '/tmp/database-import-x' -pass stdin")
        ->and(substr(implode("\n", $commands), 0, 100))->not->toContain($passphrase);
});

test('backup encryption commands keep the passphrase out of sudo arguments for non-root servers', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'user' => 'deploy',
    ]);
    $passphrase = BackupEncryption::generatePassphrase();

    $encrypt = parseCommandsByLineForSudo(collect(BackupEncryption::encryptCommands('/data/coolify/backups/a.dmp', '/data/coolify/backups/a.dmp.enc', $passphrase)), $server);
    $decrypt = parseCommandsByLineForSudo(collect(BackupEncryption::decryptCommands('/data/coolify/backups/a.dmp.enc', '/tmp/database-import-x', $passphrase)), $server);

    expect($encrypt[0])->toStartWith('# ')
        ->and($encrypt[1])->toBe("sudo rm -f '/data/coolify/backups/a.dmp.enc'")
        ->and($encrypt[2])->toStartWith("echo '{$passphrase}' | sudo openssl enc -aes-256-cbc")
        ->and($encrypt[3])->toBe("sudo rm -f '/data/coolify/backups/a.dmp'")
        ->and($decrypt[1])->toBe("sudo install -m 600 /dev/null '/tmp/database-import-x'")
        ->and($decrypt[2])->toStartWith("echo '{$passphrase}' | sudo openssl enc -d -aes-256-cbc");

    foreach ([...$encrypt, ...$decrypt] as $line) {
        expect($line)->not->toContain('sudo echo')
            ->not->toContain('bash -c')
            ->not->toMatch("/sudo [^|]*{$passphrase}/");
    }
});

test('encrypted backups decrypt to the original dump with openssl', function () {
    if (! is_executable('/usr/bin/openssl') && trim((string) shell_exec('command -v openssl')) === '') {
        $this->markTestSkipped('openssl is not installed.');
    }

    $directory = sys_get_temp_dir().'/coolify-backup-encryption-'.Str::random(8);
    mkdir($directory, 0700);
    $plain = "{$directory}/dump.sql";
    $encrypted = "{$plain}.enc";
    $restored = "{$directory}/restored.sql";
    $contents = "-- PostgreSQL database dump\nSELECT 1;\n".random_bytes(4096);
    file_put_contents($plain, $contents);
    $passphrase = BackupEncryption::generatePassphrase();

    $run = function (array $commands): SymfonyProcess {
        $process = new SymfonyProcess(['bash', '-se']);
        $process->setInput(implode("\n", $commands)."\n");
        $process->run();

        return $process;
    };

    try {
        expect($run(BackupEncryption::encryptCommands($plain, $encrypted, $passphrase))->getExitCode())->toBe(0)
            ->and(file_exists($plain))->toBeFalse()
            ->and(substr((string) file_get_contents($encrypted), 0, 8))->toBe('Salted__');

        expect($run(BackupEncryption::decryptCommands($encrypted, $restored, $passphrase))->getExitCode())->toBe(0)
            ->and(file_get_contents($restored))->toBe($contents)
            ->and(fileperms($restored) & 0777)->toBe(0600);

        $wrong = $run(BackupEncryption::decryptCommands($encrypted, "{$directory}/wrong.sql", BackupEncryption::generatePassphrase()));
        expect($wrong->getExitCode())->not->toBe(0);
    } finally {
        array_map('unlink', glob("{$directory}/*") ?: []);
        rmdir($directory);
    }
});

test('the backup job encrypts the dump, removes the plain file, and stores the passphrase encrypted', function () {
    [$job, $execution, $exception] = runBackupEncryptionStep($this, $this->server);

    expect($exception)->toBeNull()
        ->and($job->backup_location)->toBe('/data/coolify/backups/databases/team-1/db/pg-dump-db-1700000000.dmp.enc')
        ->and($job->backup_file)->toBe('/pg-dump-db-1700000000.dmp.enc')
        ->and($execution->is_encrypted)->toBeTrue()
        ->and($execution->filename)->toBe('/data/coolify/backups/databases/team-1/db/pg-dump-db-1700000000.dmp.enc')
        ->and($execution->encryption_passphrase)->toMatch('/^[a-f0-9]{64}$/');

    $raw = ScheduledDatabaseBackupExecution::query()->whereKey($execution->id)->toBase()->value('encryption_passphrase');
    expect($raw)->not->toBe($execution->encryption_passphrase)
        ->and(decrypt($raw, false))->toBe($execution->encryption_passphrase)
        ->and($execution->toArray())->not->toHaveKey('encryption_passphrase')
        ->and($execution->toJson())->not->toContain($execution->encryption_passphrase);

    Process::assertRan(fn ($process) => str_contains($process->command, "echo '{$execution->encryption_passphrase}' | openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -salt -md sha256 -in '/data/coolify/backups/databases/team-1/db/pg-dump-db-1700000000.dmp' -out '/data/coolify/backups/databases/team-1/db/pg-dump-db-1700000000.dmp.enc' -pass stdin")
        && str_contains($process->command, "rm -f '/data/coolify/backups/databases/team-1/db/pg-dump-db-1700000000.dmp'"));
});

test('the backup job uses sudo only for openssl on non-root servers', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'user' => 'deploy',
    ]);

    [, $execution, $exception] = runBackupEncryptionStep($this, $server);

    expect($exception)->toBeNull();
    Process::assertRan(fn ($process) => str_contains($process->command, "echo '{$execution->encryption_passphrase}' | sudo openssl enc -aes-256-cbc"));
});

test('the backup fails and removes the plain dump when openssl is missing', function () {
    [$job, $execution, $exception] = runBackupEncryptionStep($this, $this->server, opensslPath: '');

    expect($exception)->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toContain('openssl is not installed')
        ->and($execution->is_encrypted)->toBeFalse()
        ->and($job->backup_location)->not->toEndWith('.enc');

    Process::assertRan(fn ($process) => str_contains($process->command, "rm -f '/data/coolify/backups/databases/team-1/db/pg-dump-db-1700000000.dmp'"));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'openssl enc'));
});

test('the backup edit form saves the encryption toggle', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $this->backup->update(['encrypt_backup' => false]);

    Livewire::test(BackupEdit::class, [
        'backup' => $this->backup->fresh(),
        'availableS3Storages' => $this->team->s3s,
    ])
        ->assertSet('encryptBackup', false)
        ->assertSee('Encrypt backup files')
        ->set('encryptBackup', true)
        ->call('instantSave')
        ->assertHasNoErrors();

    expect($this->backup->fresh()->encrypt_backup)->toBeTrue();
});

test('the backup API accepts encrypt_backup on create and update', function () {
    session(['currentTeam' => $this->team]);
    $token = $this->user->createToken('test-token', ['*'])->plainTextToken;
    $headers = ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'];

    $response = $this->withHeaders($headers)
        ->postJson("/api/v1/databases/{$this->database->uuid}/backups", [
            'frequency' => 'daily',
            'encrypt_backup' => true,
        ])
        ->assertCreated();

    $backup = ScheduledDatabaseBackup::where('uuid', $response->json('uuid'))->firstOrFail();
    expect($backup->encrypt_backup)->toBeTrue();

    $this->withHeaders($headers)
        ->patchJson("/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}", ['encrypt_backup' => false])
        ->assertOk();
    expect($backup->fresh()->encrypt_backup)->toBeFalse();

    $this->withHeaders($headers)
        ->patchJson("/api/v1/databases/{$this->database->uuid}/backups/{$backup->uuid}", ['encrypt_backup' => 'yes please'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['encrypt_backup']);
});
