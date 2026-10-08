<?php

use App\Console\Commands\RotateAppKey;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Team;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Uses a new app key, like a restart after the operator changed APP_KEY and APP_PREVIOUS_KEYS.
 *
 * @param  list<string>  $previousKeys
 */
function useAppKeyForRotationTest(string $key, array $previousKeys = []): void
{
    config()->set('app.key', $key);
    config()->set('app.previous_keys', $previousKeys);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

function rawColumnForRotationTest(string $table, mixed $id, string $column): ?string
{
    return DB::table($table)->where('id', $id)->value($column);
}

function onlyKeyEncrypter(string $key): Encrypter
{
    return new Encrypter(base64_decode(Str::after($key, 'base64:')), 'AES-256-CBC');
}

beforeEach(function () {
    $this->oldKey = 'base64:'.base64_encode(random_bytes(32));
    $this->newKey = 'base64:'.base64_encode(random_bytes(32));
    useAppKeyForRotationTest($this->oldKey);

    InstanceSettings::forceCreate(['id' => 0, 'smtp_password' => 'instance-smtp-secret']);
    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->s3 = S3Storage::create([
        'name' => 'Rotation S3',
        'region' => 'us-east-1',
        'key' => 'access-key',
        'secret' => 'secret-key',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $this->team->id,
    ]);
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $this->team->id,
    ]);
    $this->execution = ScheduledDatabaseBackupExecution::create([
        'uuid' => (string) Str::uuid(),
        'scheduled_database_backup_id' => $backup->id,
        'filename' => '/data/coolify/backups/a.dmp.enc',
        'is_encrypted' => true,
        'encryption_passphrase' => str_repeat('cd', 32),
    ]);
    $this->privateKeyValue = $this->privateKey->fresh()->private_key;
});

test('discovers encrypted casts, encrypted array casts and manually encrypted columns', function () {
    $columns = collect(app(RotateAppKey::class)->encryptedColumns())
        ->mapWithKeys(fn (array $table): array => [$table['model']->getTable() => $table['columns']]);

    expect($columns['private_keys'])->toContain('private_key')
        ->and($columns['s3_storages'])->toContain('key', 'secret')
        ->and($columns['instance_settings'])->toContain('smtp_password')
        ->and($columns['scheduled_database_backup_executions'])->toBe(['encryption_passphrase'])
        ->and($columns['application_deployment_queues'])->toContain('configuration_snapshot')
        ->and($columns['server_settings'])->toContain('dns_challenge_credentials')
        ->and($columns['users'])->toContain('two_factor_secret', 'two_factor_recovery_codes')
        ->and($columns['standalone_mongodbs'])->toContain('mongo_initdb_root_password')
        ->and($columns['oauth_settings'])->toContain('client_secret');
});

test('a dry run reports values to re-encrypt without changing them', function () {
    useAppKeyForRotationTest($this->newKey, [$this->oldKey]);
    $before = rawColumnForRotationTest('s3_storages', $this->s3->id, 'secret');

    $this->artisan('security:rotate-app-key', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(rawColumnForRotationTest('s3_storages', $this->s3->id, 'secret'))->toBe($before);
});

test('rotation re-encrypts every value with the new key, including the id 0 instance settings', function () {
    useAppKeyForRotationTest($this->newKey, [$this->oldKey]);

    $this->artisan('security:rotate-app-key')->assertSuccessful();

    $newOnly = onlyKeyEncrypter($this->newKey);
    expect($newOnly->decryptString(rawColumnForRotationTest('s3_storages', $this->s3->id, 'secret')))->toBe('secret-key')
        ->and($newOnly->decryptString(rawColumnForRotationTest('s3_storages', $this->s3->id, 'key')))->toBe('access-key')
        ->and($newOnly->decryptString(rawColumnForRotationTest('instance_settings', 0, 'smtp_password')))->toBe('instance-smtp-secret')
        ->and($newOnly->decryptString(rawColumnForRotationTest('scheduled_database_backup_executions', $this->execution->id, 'encryption_passphrase')))->toBe(str_repeat('cd', 32))
        ->and($newOnly->decryptString(rawColumnForRotationTest('private_keys', $this->privateKey->id, 'private_key')))->toBe($this->privateKeyValue);

    // The old key can be removed afterwards.
    useAppKeyForRotationTest($this->newKey);
    expect(S3Storage::find($this->s3->id)->secret)->toBe('secret-key')
        ->and(InstanceSettings::find(0)->smtp_password)->toBe('instance-smtp-secret')
        ->and(ScheduledDatabaseBackupExecution::find($this->execution->id)->encryption_passphrase)->toBe(str_repeat('cd', 32));
});

test('rotation is idempotent', function () {
    useAppKeyForRotationTest($this->newKey, [$this->oldKey]);
    $this->artisan('security:rotate-app-key')->assertSuccessful();
    $afterFirstRun = rawColumnForRotationTest('s3_storages', $this->s3->id, 'secret');

    $this->artisan('security:rotate-app-key')
        ->expectsOutputToContain('Re-encrypted: 0')
        ->assertSuccessful();

    expect(rawColumnForRotationTest('s3_storages', $this->s3->id, 'secret'))->toBe($afterFirstRun);
});

test('values serialized by encrypt() keep their format', function () {
    DB::table('users')->insert([
        'name' => 'Two factor user',
        'email' => 'two-factor@example.com',
        'password' => 'hash',
        'two_factor_secret' => encrypt('TOTPSECRET'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    useAppKeyForRotationTest($this->newKey, [$this->oldKey]);

    $this->artisan('security:rotate-app-key')->assertSuccessful();

    $raw = DB::table('users')->where('email', 'two-factor@example.com')->value('two_factor_secret');
    expect(onlyKeyEncrypter($this->newKey)->decrypt($raw))->toBe('TOTPSECRET');
});

test('plain legacy values are left alone and unreadable values fail the command', function () {
    $gitlab = GitlabApp::create([
        'name' => 'GitLab',
        'api_url' => 'https://gitlab.example.com/api/v4',
        'html_url' => 'https://gitlab.example.com',
        'team_id' => $this->team->id,
    ]);
    DB::table('gitlab_apps')->where('id', $gitlab->id)->update(['webhook_token' => 'legacy-plain-token']);
    $unknownKey = onlyKeyEncrypter('base64:'.base64_encode(random_bytes(32)));
    DB::table('s3_storages')->where('id', $this->s3->id)->update(['secret' => $unknownKey->encryptString('lost')]);
    useAppKeyForRotationTest($this->newKey, [$this->oldKey]);

    $this->artisan('security:rotate-app-key')
        ->expectsOutputToContain('failed: 1')
        ->assertFailed();

    expect(DB::table('gitlab_apps')->where('id', $gitlab->id)->value('webhook_token'))->toBe('legacy-plain-token');
});

test('without previous keys old values are reported as failed and nothing is changed', function () {
    useAppKeyForRotationTest($this->newKey);
    $before = rawColumnForRotationTest('s3_storages', $this->s3->id, 'secret');

    $this->artisan('security:rotate-app-key')
        ->expectsOutputToContain('APP_PREVIOUS_KEYS is empty')
        ->assertFailed();

    expect(rawColumnForRotationTest('s3_storages', $this->s3->id, 'secret'))->toBe($before);
});

test('config reads previous keys from APP_PREVIOUS_KEYS', function () {
    $config = file_get_contents(config_path('app.php'));

    expect($config)->toContain("'previous_keys' =>")
        ->toContain("env('APP_PREVIOUS_KEYS', '')");
});
