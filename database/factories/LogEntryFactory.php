<?php

namespace Database\Factories;

use App\Models\LogEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogEntry>
 */
class LogEntryFactory extends Factory
{
    protected $model = LogEntry::class;

    public function definition(): array
    {
        return [
            'source' => LogEntry::SOURCE_SYSTEM,
            'level' => 'warning',
            'message' => fake()->sentence(),
            'context' => [],
            'logged_at' => now(),
            'created_at' => now(),
        ];
    }

    public function container(string $containerName = 'app-container'): static
    {
        return $this->state(fn (): array => [
            'source' => LogEntry::SOURCE_CONTAINER,
            'level' => 'info',
            'resource_type' => 'application',
            'resource_name' => 'demo-app',
            'container_name' => $containerName,
        ]);
    }
}
