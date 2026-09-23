<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CopyStatus;
use Database\Factories\BackupCopyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A copy of a backup file on one destination.
 *
 * @property int $id
 * @property int $backup_file_id
 * @property int $destination_id
 * @property string $remote_path
 * @property CopyStatus $status
 * @property Carbon|null $verified_at
 * @property Carbon|null $deleted_at
 * @property string|null $error
 * @property-read BackupFile $backupFile
 * @property-read Destination $destination
 */
class BackupCopy extends Model
{
    /** @use HasFactory<BackupCopyFactory> */
    use HasFactory;

    protected $fillable = [
        'backup_file_id', 'destination_id', 'remote_path', 'status', 'verified_at', 'deleted_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => CopyStatus::class,
            'verified_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BackupFile, $this> */
    public function backupFile(): BelongsTo
    {
        return $this->belongsTo(BackupFile::class);
    }

    /** @return BelongsTo<Destination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function isRestorable(): bool
    {
        return in_array($this->status, [CopyStatus::Uploaded, CopyStatus::Verified], true);
    }
}
