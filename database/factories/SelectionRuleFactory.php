<?php

namespace Database\Factories;

use App\Enums\RuleType;
use App\Models\SelectionRule;
use App\Models\ServerConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SelectionRule>
 */
class SelectionRuleFactory extends Factory
{
    protected $model = SelectionRule::class;

    public function definition(): array
    {
        return [
            'connection_id' => ServerConnection::factory(),
            'type' => RuleType::Include,
            'pattern' => '*',
            'priority' => 100,
            'is_active' => true,
        ];
    }
}
