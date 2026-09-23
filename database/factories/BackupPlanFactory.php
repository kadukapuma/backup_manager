<?php

namespace Database\Factories;

use App\Models\BackupPlan;
use App\Models\ServerConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupPlan>
 */
class BackupPlanFactory extends Factory
{
    protected $model = BackupPlan::class;

    public function definition(): array
    {
        return [
            'name' => 'plan-'.$this->faker->unique()->numberBetween(1, 999999),
            'connection_id' => ServerConnection::factory(),
            'cron_expression' => '0 2 * * *',
            'timezone' => 'Asia/Colombo',
            'compression' => 'zstd',
            'encrypt' => true,
            'retention' => ['keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 6],
            'all_included_databases' => true,
            'is_active' => true,
        ];
    }
}
