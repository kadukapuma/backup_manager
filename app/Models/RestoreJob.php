<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RestoreMode;
use App\Enums\RestoreStatus;
use Database\Factories\RestoreJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $backup_file_id
 * @property int|null $source_destination_id
 * @property int $target_connection_id
 * @property string $target_database
 * @property RestoreMode $mode
 * @property int|null $safety_backup_file_id
 * @property RestoreStatus $status
 * @property string|null $progress_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $requested_by
 * @property string|null $error
 * @property array<string, mixed>|null $post_check
 * @property string|null $age_identity
 * @property string|null $log
 * @property-read BackupFile $backupFile
 * @property-read Destination|null $sourceDestination
 * @property-read ServerConnection $targetConnection
 */
class RestoreJob extends Model
{
    /** @use HasFactory<RestoreJobFactory> */
    use HasFactory;

    protected $fillable = [
        'backup_file_id', 'source_destination_id', 'target_connection_id', 'target_database', 'mode',
        'safety_backup_file_id', 'status', 'progress_message', 'started_at', 'finished_at',
        'requested_by', 'error', 'post_check', 'age_identity', 'log',
    ];

    protected $hidden = ['age_identity'];

    protected function casts(): array
    {
        return [
            'mode' => RestoreMode::class,
            'status' => RestoreStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'post_check' => 'array',
            'age_identity' => 'encrypted',
        ];
    }

    /** @return BelongsTo<BackupFile, $this> */
    public function backupFile(): BelongsTo
    {
        return $this->belongsTo(BackupFile::class);
    }

    /** @return BelongsTo<BackupFile, $this> */
    public function safetyBackupFile(): BelongsTo
    {
        return $this->belongsTo(BackupFile::class, 'safety_backup_file_id');
    }

    /** @return BelongsTo<Destination, $this> */
    public function sourceDestination(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'source_destination_id');
    }

    /** @return BelongsTo<ServerConnection, $this> */
    public function targetConnection(): BelongsTo
    {
        return $this->belongsTo(ServerConnection::class, 'target_connection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
