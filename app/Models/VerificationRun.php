<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VerificationLevel;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $backup_file_id
 * @property int|null $backup_copy_id
 * @property VerificationLevel $level
 * @property VerificationStatus $status
 * @property array<string, mixed>|null $details
 */
class VerificationRun extends Model
{
    protected $fillable = ['backup_file_id', 'backup_copy_id', 'level', 'status', 'details'];

    protected function casts(): array
    {
        return [
            'level' => VerificationLevel::class,
            'status' => VerificationStatus::class,
            'details' => 'array',
        ];
    }

    /** @return BelongsTo<BackupFile, $this> */
    public function backupFile(): BelongsTo
    {
        return $this->belongsTo(BackupFile::class);
    }

    /** @return BelongsTo<BackupCopy, $this> */
    public function backupCopy(): BelongsTo
    {
        return $this->belongsTo(BackupCopy::class);
    }
}
