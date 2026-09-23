<?php

declare(strict_types=1);

namespace App\Actions\Databases;

use App\Enums\AuditAction;
use App\Enums\DatabaseState;
use App\Enums\StateSource;
use App\Models\Database;
use App\Models\SelectionRule;
use App\Services\Audit\AuditLogger;
use App\Services\Discovery\StateResolver;
use Illuminate\Support\Collection;

class ChangeDatabaseState
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Set a manual state, or pass null to hand the databases back to rules/policy.
     *
     * @param  Collection<int, Database>  $databases
     */
    public function handle(Collection $databases, ?DatabaseState $state): int
    {
        $changed = [];

        foreach ($databases->groupBy('connection_id') as $group) {
            /** @var Database $first */
            $first = $group->first();
            $connection = $first->serverConnection;
            /** @var Collection<int, SelectionRule> $rules */
            $rules = $connection->selectionRules()->get();

            foreach ($group as $db) {
                [$newState, $source] = $state !== null
                    ? [$state, StateSource::Manual]
                    : StateResolver::resolve($db->name, $connection, $rules);

                if ($db->state !== $newState || $db->state_source !== $source) {
                    $changed[] = ['db' => $db->name, 'connection' => $connection->name, 'from' => $db->state->value, 'to' => $newState->value];
                    $db->forceFill(['state' => $newState, 'state_source' => $source])->save();
                }
            }
        }

        if ($changed !== []) {
            $this->audit->log(AuditAction::DatabaseStateChanged, null, [
                'mode' => $state?->value ?? 'automatic',
                'count' => count($changed),
                'databases' => array_slice($changed, 0, 200),
            ]);
        }

        return count($changed);
    }
}
