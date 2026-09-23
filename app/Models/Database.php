<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BackupFileStatus;
use App\Enums\DatabaseState;
use App\Enums\StateSource;
use Database\Factories\DatabaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A database discovered on a connection.
 *
 * @property int $id
 * @property int $connection_id
 * @property string $name
 * @property DatabaseState $state
 * @property StateSource $state_source
 * @property int $size_bytes
 * @property int $table_count
 * @property string|null $default_charset
 * @property string|null $default_collation
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $missing_since
 * @property Carbon|null $last_success_at
 * @property-read ServerConnection $serverConnection
 */
class Database extends Model
{
    /** @use HasFactory<DatabaseFactory> */
    use HasFactory;

    protected $fillable = [
        'connection_id', 'name', 'state', 'state_source', 'size_bytes', 'table_count',
        'default_charset', 'default_collation', 'first_seen_at', 'last_seen_at',
        'missing_since', 'last_success_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => DatabaseState::class,
            'state_source' => StateSource::class,
            'size_bytes' => 'integer',
            'table_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'missing_since' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ServerConnection, $this> */
    public function serverConnection(): BelongsTo
    {
        return $this->belongsTo(ServerConnection::class, 'connection_id');
    }

    /** @return HasMany<BackupFile, $this> */
    public function backupFiles(): HasMany
    {
        return $this->hasMany(BackupFile::class);
    }

    /** @return HasOne<BackupFile, $this> */
    public function latestSuccessfulBackup(): HasOne
    {
        return $this->hasOne(BackupFile::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('status', BackupFileStatus::Success->value),
        );
    }

    /**
     * @param  Builder<Database>  $query
     */
    public function scopeIncluded(Builder $query): void
    {
        $query->where('state', DatabaseState::Included->value)->whereNull('missing_since');
    }

    public function lockKey(): string
    {
        return self::lockKeyFor($this->connection_id, $this->name);
    }

    /**
     * Shared by backups, retention and restores so only one of them touches a
     * database at a time, even for databases not (yet) in the catalog.
     */
    public static function lockKeyFor(int $connectionId, string $name): string
    {
        return 'bm:db:'.$connectionId.':'.$name;
    }
}
