<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConnectionDriver;
use App\Enums\NewDatabasePolicy;
use App\Enums\TestStatus;
use Database\Factories\ServerConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A database server the panel can connect to. Named "ServerConnection" (table
 * `connections`) to avoid clashing with Eloquent's own `$connection` property.
 *
 * @property int $id
 * @property string $name
 * @property ConnectionDriver $driver
 * @property string $host
 * @property int $port
 * @property string $username
 * @property string|null $password
 * @property string|null $socket
 * @property NewDatabasePolicy $new_database_policy
 * @property bool $is_active
 * @property Carbon|null $last_tested_at
 * @property TestStatus|null $last_test_status
 * @property string|null $last_test_message
 * @property Carbon|null $last_discovered_at
 */
class ServerConnection extends Model
{
    /** @use HasFactory<ServerConnectionFactory> */
    use HasFactory;

    protected $table = 'connections';

    protected $fillable = [
        'name', 'driver', 'host', 'port', 'username', 'password', 'socket',
        'new_database_policy', 'is_active',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'driver' => ConnectionDriver::class,
            'port' => 'integer',
            'password' => 'encrypted',
            'new_database_policy' => NewDatabasePolicy::class,
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_test_status' => TestStatus::class,
            'last_discovered_at' => 'datetime',
        ];
    }

    /** @return HasMany<Database, $this> */
    public function databases(): HasMany
    {
        return $this->hasMany(Database::class, 'connection_id');
    }

    /** @return HasMany<SelectionRule, $this> */
    public function selectionRules(): HasMany
    {
        return $this->hasMany(SelectionRule::class, 'connection_id');
    }

    /** @return HasMany<BackupPlan, $this> */
    public function backupPlans(): HasMany
    {
        return $this->hasMany(BackupPlan::class, 'connection_id');
    }

    public function hasBackupHistory(): bool
    {
        return BackupFile::query()
            ->whereIn('database_id', $this->databases()->select('id'))
            ->exists();
    }
}
