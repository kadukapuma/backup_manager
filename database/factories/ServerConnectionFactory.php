<?php

namespace Database\Factories;

use App\Enums\ConnectionDriver;
use App\Enums\NewDatabasePolicy;
use App\Models\ServerConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerConnection>
 */
class ServerConnectionFactory extends Factory
{
    protected $model = ServerConnection::class;

    public function definition(): array
    {
        return [
            'name' => 'server-'.$this->faker->unique()->numberBetween(1, 999999),
            'driver' => ConnectionDriver::Mariadb,
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'backup',
            'password' => 'secret-password',
            'socket' => null,
            'new_database_policy' => NewDatabasePolicy::Pending,
            'is_active' => true,
        ];
    }
}
