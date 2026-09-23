<?php

namespace Database\Factories;

use App\Enums\BackupFileStatus;
use App\Models\BackupFile;
use App\Models\BackupRun;
use App\Models\Database;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupFile>
 */
class BackupFileFactory extends Factory
{
    protected $model = BackupFile::class;

    public function definition(): array
    {
        return [
            'backup_run_id' => BackupRun::factory(),
            'database_id' => Database::factory(),
            'filename' => 'db__20260101-020000__scheduled.sql.zst.age',
            'size_bytes' => 2048,
            'sha256' => hash('sha256', (string) $this->faker->uuid()),
            'md5' => md5((string) $this->faker->uuid()),
            'duration_ms' => 1500,
            'status' => BackupFileStatus::Success,
            'manifest' => ['table_count' => 12, 'default_charset' => 'utf8mb4', 'default_collation' => 'utf8mb4_unicode_ci'],
        ];
    }
}
