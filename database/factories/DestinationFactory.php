<?php

namespace Database\Factories;

use App\Enums\DestinationType;
use App\Models\Destination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Destination>
 */
class DestinationFactory extends Factory
{
    protected $model = Destination::class;

    public function definition(): array
    {
        return [
            'name' => 'dest-'.$this->faker->unique()->numberBetween(1, 999999),
            'type' => DestinationType::Local,
            'config' => [],
            'base_path' => '/var/backups/db',
            'is_active' => true,
        ];
    }

    public function sftp(): static
    {
        return $this->state([
            'type' => DestinationType::Sftp,
            'config' => ['host' => 'backup.example.com', 'port' => 22, 'user' => 'bk', 'password' => 'sftp-secret'],
            'base_path' => 'backups',
        ]);
    }

    public function s3(): static
    {
        return $this->state([
            'type' => DestinationType::S3,
            'config' => [
                'provider' => 'AWS', 'region' => 'ap-south-1', 'endpoint' => '', 'bucket' => 'kreethya-backups',
                'access_key_id' => 'AKIAEXAMPLE', 'secret_access_key' => 's3-secret',
            ],
            'base_path' => 'db',
        ]);
    }
}
