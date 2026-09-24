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
 * @property bool $ssh_enabled
 * @property string|null $ssh_host
 * @property int $ssh_port
 * @property string|null $ssh_user
 * @property string|null $ssh_private_key
 * @property string|null $ssh_public_key
 * @property string|null $ssh_host_key known_hosts lines pinned by the first successful test
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
        'ssh_enabled', 'ssh_host', 'ssh_port', 'ssh_user',
        'new_database_policy', 'is_active',
    ];

    protected $hidden = ['password', 'ssh_private_key'];

    protected function casts(): array
    {
        return [
            'driver' => ConnectionDriver::class,
            'port' => 'integer',
            'password' => 'encrypted',
            'ssh_enabled' => 'boolean',
            'ssh_port' => 'integer',
            'ssh_private_key' => 'encrypted',
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

    public function usesSsh(): bool
    {
        return $this->ssh_enabled && $this->ssh_host !== null && $this->ssh_host !== '';
    }

    /**
     * The line to add to ~/.ssh/authorized_keys on the remote server. The
     * options limit the key to one port forward to the database port.
     */
    public function sshAuthorizedKeysLine(): ?string
    {
        if ($this->ssh_public_key === null || $this->ssh_public_key === '') {
            return null;
        }

        $target = (str_contains($this->host, ':') ? '['.$this->host.']' : $this->host).':'.$this->port;

        return 'restrict,port-forwarding,permitopen="'.$target.'" '.trim($this->ssh_public_key);
    }

    public function hasBackupHistory(): bool
    {
        return BackupFile::query()
            ->whereIn('database_id', $this->databases()->select('id'))
            ->exists();
    }
}
