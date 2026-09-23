<?php

namespace Database\Factories;

use App\Enums\CopyStatus;
use App\Models\BackupCopy;
use App\Models\BackupFile;
use App\Models\Destination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupCopy>
 */
class BackupCopyFactory extends Factory
{
    protected $model = BackupCopy::class;

    public function definition(): array
    {
        return [
            'backup_file_id' => BackupFile::factory(),
            'destination_id' => Destination::factory(),
            'remote_path' => 'db/file.sql.zst.age',
            'status' => CopyStatus::Verified,
            'verified_at' => now(),
        ];
    }
}
