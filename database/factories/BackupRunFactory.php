<?php

namespace Database\Factories;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\BackupRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupRun>
 */
class BackupRunFactory extends Factory
{
    protected $model = BackupRun::class;

    public function definition(): array
    {
        return [
            'backup_plan_id' => null,
            'connection_id' => null,
            'trigger' => RunTrigger::Manual,
            'status' => RunStatus::Success,
            'started_at' => now(),
            'finished_at' => now(),
            'summary' => [],
        ];
    }
}
