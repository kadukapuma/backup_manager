<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RuleType;
use Database\Factories\SelectionRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $connection_id
 * @property RuleType $type
 * @property string $pattern
 * @property int $priority
 * @property bool $is_active
 */
class SelectionRule extends Model
{
    /** @use HasFactory<SelectionRuleFactory> */
    use HasFactory;

    protected $fillable = ['connection_id', 'type', 'pattern', 'priority', 'is_active'];

    protected function casts(): array
    {
        return [
            'type' => RuleType::class,
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ServerConnection, $this> */
    public function serverConnection(): BelongsTo
    {
        return $this->belongsTo(ServerConnection::class, 'connection_id');
    }
}
