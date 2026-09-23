<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use Database\Factories\BackupRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $backup_plan_id
 * @property int|null $connection_id
 * @property RunTrigger $trigger
 * @property RunStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $triggered_by
 * @property array<string, mixed>|null $summary
 * @property Carbon $created_at
 * @property-read BackupPlan|null $plan
 */
class BackupRun extends Model
{
    /** @use HasFactory<BackupRunFactory> */
    use HasFactory;

    protected $fillable = [
        'backup_plan_id', 'connection_id', 'trigger', 'status', 'started_at', 'finished_at',
        'triggered_by', 'summary',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => RunTrigger::class,
            'status' => RunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    /** @return BelongsTo<BackupPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(BackupPlan::class, 'backup_plan_id');
    }

    /** @return BelongsTo<ServerConnection, $this> */
    public function serverConnection(): BelongsTo
    {
        return $this->belongsTo(ServerConnection::class, 'connection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /** @return HasMany<BackupFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(BackupFile::class);
    }
}
