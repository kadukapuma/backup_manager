<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\RetentionPolicy;
use Database\Factories\BackupPlanFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property int $connection_id
 * @property string $cron_expression
 * @property string $timezone
 * @property string $compression
 * @property bool $encrypt
 * @property array{keep_daily: int, keep_weekly: int, keep_monthly: int} $retention
 * @property bool $all_included_databases
 * @property bool $is_active
 * @property Carbon|null $last_run_at
 * @property Carbon|null $next_run_at
 * @property-read ServerConnection $serverConnection
 */
class BackupPlan extends Model
{
    /** @use HasFactory<BackupPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'connection_id', 'cron_expression', 'timezone', 'compression', 'encrypt',
        'retention', 'all_included_databases', 'is_active', 'last_run_at', 'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'encrypt' => 'boolean',
            'retention' => 'array',
            'all_included_databases' => 'boolean',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ServerConnection, $this> */
    public function serverConnection(): BelongsTo
    {
        return $this->belongsTo(ServerConnection::class, 'connection_id');
    }

    /** @return BelongsToMany<Database, $this> */
    public function databases(): BelongsToMany
    {
        return $this->belongsToMany(Database::class, 'backup_plan_database');
    }

    /** @return BelongsToMany<Destination, $this> */
    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(Destination::class, 'backup_plan_destination');
    }

    /** @return HasMany<BackupRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }

    public function retentionPolicy(): RetentionPolicy
    {
        return RetentionPolicy::fromArray($this->retention);
    }

    /**
     * Databases this plan backs up right now. Excluded, pending and missing
     * databases are never backed up, even when picked explicitly.
     *
     * @return Collection<int, Database>
     */
    public function targetDatabases(): Collection
    {
        if ($this->all_included_databases) {
            return Database::query()
                ->where('connection_id', $this->connection_id)
                ->included()
                ->orderBy('name')
                ->get();
        }

        return $this->databases()->included()->orderBy('name')->get();
    }
}
