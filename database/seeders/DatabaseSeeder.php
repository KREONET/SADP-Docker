<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\RefusesProductionSeeding;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use RefusesProductionSeeding;

    public function run(): void
    {
        $this->refuseToRunInProduction();

        $this->call([
            InstanceSettingsSeeder::class,
            UserSeeder::class,
            TeamSeeder::class,
            PrivateKeySeeder::class,
            PopulateSshKeysDirectorySeeder::class,
            ServerSeeder::class,
            ServerSettingSeeder::class,
            ProjectSeeder::class,
            StandaloneDockerSeeder::class,
            GithubAppSeeder::class,
            GitlabAppSeeder::class,
            ApplicationSeeder::class,
            ApplicationSettingsSeeder::class,
            LocalPersistentVolumeSeeder::class,
            S3StorageSeeder::class,
            StandalonePostgresqlSeeder::class,
            OauthSettingSeeder::class,
            SentinelSeeder::class,
            CaSslCertSeeder::class,
            PersonalAccessTokenSeeder::class,
        ]);

        if (in_array(config('app.env'), ['local', 'development', 'dev'], true)) {
            $this->call([
                DevelopmentRailpackExamplesSeeder::class,
            ]);
        }
    }
}
