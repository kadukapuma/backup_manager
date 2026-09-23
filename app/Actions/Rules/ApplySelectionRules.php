<?php

declare(strict_types=1);

namespace App\Actions\Rules;

use App\Enums\StateSource;
use App\Models\Database;
use App\Models\ServerConnection;
use App\Services\Discovery\StateResolver;

/**
 * Re-evaluates rules for databases whose state was not set by hand.
 */
class ApplySelectionRules
{
    /**
     * @return int number of databases whose state changed
     */
    public function handle(ServerConnection $connection): int
    {
        $rules = $connection->selectionRules()->get();
        $changed = 0;

        $connection->databases()
            ->where('state_source', '!=', StateSource::Manual->value)
            ->whereNull('missing_since')
            ->get()
            ->each(function (Database $db) use ($connection, $rules, &$changed): void {
                [$state, $source] = StateResolver::resolve($db->name, $connection, $rules);
                if ($db->state !== $state || $db->state_source !== $source) {
                    $db->forceFill(['state' => $state, 'state_source' => $source])->save();
                    $changed++;
                }
            });

        return $changed;
    }
}
