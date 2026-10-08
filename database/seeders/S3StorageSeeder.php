<?php

namespace Database\Seeders;

use App\Models\S3Storage;
use Database\Seeders\Concerns\RefusesProductionSeeding;
use Illuminate\Database\Seeder;

class S3StorageSeeder extends Seeder
{
    use RefusesProductionSeeding;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->refuseToRunInProduction();

        S3Storage::create([
            'uuid' => 'minio',
            'name' => 'Local MinIO',
            'description' => 'Local MinIO S3 Storage',
            'key' => 'minioadmin',
            'secret' => 'minioadmin',
            'bucket' => 'local',
            'endpoint' => 'http://coolify-minio:9000',
            'team_id' => 0,
            'is_usable' => true,
        ]);
    }
}
