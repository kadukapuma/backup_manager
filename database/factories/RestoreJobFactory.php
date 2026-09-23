<?php

namespace Database\Factories;

use App\Enums\RestoreMode;
use App\Enums\RestoreStatus;
use App\Models\BackupFile;
use App\Models\RestoreJob;
use App\Models\ServerConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestoreJob>
 */
class RestoreJobFactory extends Factory
{
    protected $model = RestoreJob::class;

    public function definition(): array
    {
        return [
            'backup_file_id' => BackupFile::factory(),
            'target_connection_id' => ServerConnection::factory(),
            'target_database' => 'restored_db',
            'mode' => RestoreMode::NewCopy,
            'status' => RestoreStatus::Queued,
        ];
    }
}
