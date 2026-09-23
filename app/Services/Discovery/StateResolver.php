<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Enums\DatabaseState;
use App\Enums\NewDatabasePolicy;
use App\Enums\RuleType;
use App\Enums\StateSource;
use App\Models\SelectionRule;
use App\Models\ServerConnection;

/**
 * Decides the state of a database that was not set by hand: the first
 * matching rule wins, otherwise the connection's new-database policy applies.
 */
final class StateResolver
{
    /**
     * @param  iterable<SelectionRule>  $rules
     * @return array{0: DatabaseState, 1: StateSource}
     */
    public static function resolve(string $name, ServerConnection $connection, iterable $rules): array
    {
        $rule = RuleMatcher::firstMatch($name, $rules);

        if ($rule !== null) {
            return [$rule->type === RuleType::Include ? DatabaseState::Included : DatabaseState::Excluded, StateSource::Rule];
        }

        return [
            $connection->new_database_policy === NewDatabasePolicy::AutoInclude ? DatabaseState::Included : DatabaseState::Pending,
            StateSource::Policy,
        ];
    }
}
