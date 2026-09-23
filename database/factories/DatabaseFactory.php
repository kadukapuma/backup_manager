<?php

namespace Database\Factories;

use App\Enums\DatabaseState;
use App\Enums\StateSource;
use App\Models\Database;
use App\Models\ServerConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Database>
 */
class DatabaseFactory extends Factory
{
    protected $model = Database::class;

    public function definition(): array
    {
        return [
            'connection_id' => ServerConnection::factory(),
            'name' => 'db_'.$this->faker->unique()->numberBetween(1, 999999),
            'state' => DatabaseState::Included,
            'state_source' => StateSource::Manual,
            'size_bytes' => 1024 * 1024,
            'table_count' => 12,
            'default_charset' => 'utf8mb4',
            'default_collation' => 'utf8mb4_unicode_ci',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(['state' => DatabaseState::Pending, 'state_source' => StateSource::Policy]);
    }

    public function excluded(): static
    {
        return $this->state(['state' => DatabaseState::Excluded]);
    }
}
