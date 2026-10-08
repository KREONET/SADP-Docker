<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\RefusesProductionSeeding;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    use RefusesProductionSeeding;

    public function run(): void
    {
        $this->refuseToRunInProduction();

        User::factory()->create([
            'id' => 0,
            'name' => 'Root User',
            'email' => 'test@example.com',
        ]);
        User::factory()->create([
            'name' => 'Normal User (but in root team)',
            'email' => 'test2@example.com',
        ]);
        User::factory()->create([
            'name' => 'Normal User (not in root team)',
            'email' => 'test3@example.com',
        ]);

    }
}
