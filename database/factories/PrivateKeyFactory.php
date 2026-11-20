<?php

namespace Database\Factories;

use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use phpseclib3\Crypt\EC;

/**
 * @extends Factory<PrivateKey>
 */
class PrivateKeyFactory extends Factory
{
    protected $model = PrivateKey::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'private_key' => $this->privateKey(),
            'team_id' => Team::factory(),
            'is_git_related' => false,
        ];
    }

    /**
     * A generated Ed25519 key, shared by every factory call of this process to keep tests fast.
     */
    private function privateKey(): string
    {
        static $key = null;

        return $key ??= EC::createKey('Ed25519')->toString('OpenSSH', ['comment' => 'coolify-factory']);
    }
}
