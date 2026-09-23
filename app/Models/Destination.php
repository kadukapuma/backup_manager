<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DestinationType;
use App\Enums\TestStatus;
use Database\Factories\DestinationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property DestinationType $type
 * @property array<string, mixed> $config
 * @property string $base_path
 * @property bool $is_active
 * @property Carbon|null $last_tested_at
 * @property TestStatus|null $last_test_status
 * @property string|null $last_test_message
 * @property int|null $free_space_bytes
 * @property int|null $used_bytes
 */
class Destination extends Model
{
    /** @use HasFactory<DestinationFactory> */
    use HasFactory;

    protected $fillable = ['name', 'type', 'config', 'base_path', 'is_active'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'type' => DestinationType::class,
            'config' => 'encrypted:array',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_test_status' => TestStatus::class,
            'free_space_bytes' => 'integer',
            'used_bytes' => 'integer',
        ];
    }

    /** @return HasMany<BackupCopy, $this> */
    public function copies(): HasMany
    {
        return $this->hasMany(BackupCopy::class);
    }

    /** @return BelongsToMany<BackupPlan, $this> */
    public function backupPlans(): BelongsToMany
    {
        return $this->belongsToMany(BackupPlan::class, 'backup_plan_destination');
    }

    /**
     * Config safe to send to the browser: secrets replaced by a set/unset flag.
     *
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        $config = $this->config;
        foreach ($this->type->secretKeys() as $key) {
            $config[$key] = null;
            $config[$key.'_is_set'] = ! empty($this->config[$key] ?? null);
        }

        return $config;
    }

    /**
     * Destination-relative path (stored in backup_copies.remote_path) for a
     * path below base_path. An absolute base_path stays absolute.
     */
    public function pathFor(string $relative): string
    {
        $base = rtrim($this->base_path, '/');
        $relative = ltrim($relative, '/');

        if ($base === '') {
            return $this->type === DestinationType::Local ? '/'.$relative : $relative;
        }

        return $base.'/'.$relative;
    }
}
