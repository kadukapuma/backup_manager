<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannelType;
use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property NotificationChannelType $type
 * @property array<string, mixed> $config
 * @property list<string> $events
 * @property bool $is_active
 */
class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
    use HasFactory;

    protected $fillable = ['name', 'type', 'config', 'events', 'is_active'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'type' => NotificationChannelType::class,
            'config' => 'encrypted:array',
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public function mailRecipients(): array
    {
        $raw = (string) ($this->config['recipients'] ?? '');

        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $raw) ?: [])));
    }
}
