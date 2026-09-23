<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BackupFileStatus;
use Database\Factories\BackupFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One database dump inside a run.
 *
 * @property int $id
 * @property int $backup_run_id
 * @property int $database_id
 * @property string|null $filename
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property string|null $md5
 * @property int|null $duration_ms
 * @property BackupFileStatus $status
 * @property string|null $error
 * @property array<string, mixed>|null $manifest
 * @property string|null $log
 * @property Carbon $created_at
 * @property-read BackupRun $run
 * @property-read Database $database
 */
class BackupFile extends Model
{
    /** @use HasFactory<BackupFileFactory> */
    use HasFactory;

    protected $fillable = [
        'backup_run_id', 'database_id', 'filename', 'size_bytes', 'sha256', 'md5', 'duration_ms',
        'status', 'error', 'manifest', 'log', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'status' => BackupFileStatus::class,
            'manifest' => 'array',
        ];
    }

    /** @return BelongsTo<BackupRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class, 'backup_run_id');
    }

    /** @return BelongsTo<Database, $this> */
    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    /** @return HasMany<BackupCopy, $this> */
    public function copies(): HasMany
    {
        return $this->hasMany(BackupCopy::class);
    }

    /** @return HasMany<VerificationRun, $this> */
    public function verifications(): HasMany
    {
        return $this->hasMany(VerificationRun::class);
    }

    public function manifestFilename(): string
    {
        return $this->filename.'.manifest.json';
    }
}
