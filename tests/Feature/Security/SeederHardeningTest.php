<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\S3Storage;
use App\Models\Team;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\GithubAppSeeder;
use Database\Seeders\PersonalAccessTokenSeeder;
use Database\Seeders\PrivateKeySeeder;
use Database\Seeders\S3StorageSeeder;
use Database\Seeders\UserSeeder;
use Database\Seeders\WindowsDockerDesktopKeySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const LEGACY_WINDOWS_PRIVATE_KEY = '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
';

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    if (Team::find(0) === null) {
        Team::factory()->create(['id' => 0]);
    }
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');

    $this->storagePath = sys_get_temp_dir().'/coolify-seeder-test-'.uniqid();
    File::ensureDirectoryExists($this->storagePath);
    $this->originalStoragePath = $this->app->storagePath();
    $this->app->useStoragePath($this->storagePath);
});

afterEach(function () {
    $this->app->useStoragePath($this->originalStoragePath);
    File::deleteDirectory($this->storagePath);
    putenv('SEED_DEMO_DATA_IN_PRODUCTION');
    unset($_ENV['SEED_DEMO_DATA_IN_PRODUCTION'], $_SERVER['SEED_DEMO_DATA_IN_PRODUCTION']);
});

describe('demo seeders', function () {
    it('refuse to run in production', function (string $seeder) {
        $this->app['env'] = 'production';

        expect(fn () => $this->app->make($seeder)->run())
            ->toThrow(RuntimeException::class, 'SEED_DEMO_DATA_IN_PRODUCTION=true');
    })->with([
        DatabaseSeeder::class,
        UserSeeder::class,
        PrivateKeySeeder::class,
        GithubAppSeeder::class,
        S3StorageSeeder::class,
        PersonalAccessTokenSeeder::class,
    ]);

    it('run in production only with the explicit override', function () {
        $this->app['env'] = 'production';
        putenv('SEED_DEMO_DATA_IN_PRODUCTION=true');
        $_ENV['SEED_DEMO_DATA_IN_PRODUCTION'] = 'true';
        $_SERVER['SEED_DEMO_DATA_IN_PRODUCTION'] = 'true';

        $this->app->make(S3StorageSeeder::class)->run();

        expect(S3Storage::query()->where('name', 'Local MinIO')->exists())->toBeTrue();
    });

    it('still run outside production', function () {
        $this->app->make(S3StorageSeeder::class)->run();

        expect(S3Storage::query()->where('name', 'Local MinIO')->exists())->toBeTrue();
    });

    it('no longer turn the removed two-step confirmation bypass on', function () {
        expect(file_exists(database_path('seeders/DisableTwoStepConfirmationSeeder.php')))->toBeFalse()
            ->and(file_get_contents(database_path('seeders/DatabaseSeeder.php')))->not->toContain('DisableTwoStepConfirmation');
    });
});

describe('windows docker desktop key', function () {
    it('generates a per-install key instead of the published one and writes its public key', function () {
        $this->app->make(WindowsDockerDesktopKeySeeder::class)->run();

        $key = PrivateKey::find(0);
        $publicKey = PrivateKey::extractPublicKeyFromPrivate($key->private_key);

        expect($key)->not->toBeNull()
            ->and(WindowsDockerDesktopKeySeeder::isLegacyKey($key))->toBeFalse()
            ->and(file_get_contents(database_path('seeders/ProductionSeeder.php')))->not->toContain('b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW')
            ->and(File::get(WindowsDockerDesktopKeySeeder::publicKeyPath()))->toBe(trim($publicKey)." coolify@coolify-instance\n");
    });

    it('generates a different key for every install', function () {
        $this->app->make(WindowsDockerDesktopKeySeeder::class)->run();
        $first = PrivateKey::find(0)->private_key;
        PrivateKey::find(0)->delete();

        $this->app->make(WindowsDockerDesktopKeySeeder::class)->run();

        expect(PrivateKey::find(0)->private_key)->not->toBe($first);
    });

    it('keeps the generated key on later runs', function () {
        $this->app->make(WindowsDockerDesktopKeySeeder::class)->run();
        $first = PrivateKey::find(0)->private_key;

        $this->app->make(WindowsDockerDesktopKeySeeder::class)->run();

        expect(PrivateKey::find(0)->private_key)->toBe($first);
    });

    it('replaces the published legacy key', function () {
        PrivateKey::unguarded(fn () => PrivateKey::create([
            'id' => 0,
            'team_id' => 0,
            'name' => 'Testing-host',
            'private_key' => LEGACY_WINDOWS_PRIVATE_KEY,
        ]));
        expect(WindowsDockerDesktopKeySeeder::isLegacyKey(PrivateKey::find(0)))->toBeTrue();

        $this->app->make(WindowsDockerDesktopKeySeeder::class)->run();

        expect(WindowsDockerDesktopKeySeeder::isLegacyKey(PrivateKey::find(0)))->toBeFalse()
            ->and(File::get(WindowsDockerDesktopKeySeeder::publicKeyPath()))->not->toContain(WindowsDockerDesktopKeySeeder::LEGACY_PUBLIC_KEY);
    });
});
