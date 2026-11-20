<?php

namespace Database\Factories;

use App\Models\ForgejoApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForgejoApp>
 */
class ForgejoAppFactory extends Factory
{
    protected $model = ForgejoApp::class;

    public function definition(): array
    {
        return [
            'name' => 'company-forgejo',
            'html_url' => 'https://93.184.216.34',
            'login' => 'coolify-bot',
            'access_token' => 'forgejo-token-'.fake()->bothify('????####'),
            'is_system_wide' => true,
            'team_id' => 0,
        ];
    }
}
