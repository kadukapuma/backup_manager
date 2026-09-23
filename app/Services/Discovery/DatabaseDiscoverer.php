<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Enums\DatabaseState;
use App\Enums\NotificationEvent;
use App\Models\Database;
use App\Models\ServerConnection;
use App\Services\Database\DatabaseServerClient;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Collection;

/**
 * Syncs the `databases` table with what the server reports. Never deletes
 * rows: databases that disappear get `missing_since` so their history stays.
 */
class DatabaseDiscoverer
{
    public function __construct(
        private readonly DatabaseServerClient $client,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @return array{new: list<string>, pending: list<string>, missing: list<string>, reappeared: list<string>, total: int}
     */
    public function discover(ServerConnection $connection): array
    {
        $found = $this->client->listDatabases($connection);
        $rules = $connection->selectionRules()->get();
        /** @var Collection<string, Database> $existing */
        $existing = $connection->databases()->get()->keyBy('name');
        $now = now();

        $result = ['new' => [], 'pending' => [], 'missing' => [], 'reappeared' => [], 'total' => count($found)];
        $seen = [];

        foreach ($found as $info) {
            $seen[$info->name] = true;
            $stats = [
                'size_bytes' => $info->sizeBytes,
                'table_count' => $info->tableCount,
                'default_charset' => $info->charset,
                'default_collation' => $info->collation,
                'last_seen_at' => $now,
            ];

            $db = $existing->get($info->name);
            if ($db !== null) {
                if ($db->missing_since !== null) {
                    $result['reappeared'][] = $info->name;
                }
                $db->fill([...$stats, 'missing_since' => null])->save();

                continue;
            }

            [$state, $source] = StateResolver::resolve($info->name, $connection, $rules);
            Database::query()->create([
                ...$stats,
                'connection_id' => $connection->id,
                'name' => $info->name,
                'state' => $state,
                'state_source' => $source,
                'first_seen_at' => $now,
            ]);

            $result['new'][] = $info->name;
            if ($state === DatabaseState::Pending) {
                $result['pending'][] = $info->name;
            }
        }

        foreach ($existing as $name => $db) {
            if (! isset($seen[$name]) && $db->missing_since === null) {
                $db->forceFill(['missing_since' => $now])->save();
                $result['missing'][] = (string) $name;
            }
        }

        $connection->forceFill(['last_discovered_at' => $now])->save();

        $this->notify($connection, $result);

        return $result;
    }

    /**
     * @param  array{new: list<string>, pending: list<string>, missing: list<string>, reappeared: list<string>, total: int}  $result
     */
    private function notify(ServerConnection $connection, array $result): void
    {
        $url = url('/databases?connection='.$connection->id);

        if ($result['pending'] !== []) {
            $this->notifier->send(
                NotificationEvent::DatabasePending,
                count($result['pending']).' new database(s) waiting for approval on '.$connection->name,
                [
                    'These databases were found and will NOT be backed up until someone approves them:',
                    implode(', ', $result['pending']),
                ],
                $url,
            );
        }

        $missingIncluded = Database::query()
            ->where('connection_id', $connection->id)
            ->whereIn('name', $result['missing'])
            ->where('state', DatabaseState::Included->value)
            ->pluck('name')
            ->all();

        if ($missingIncluded !== []) {
            $this->notifier->send(
                NotificationEvent::DatabaseMissing,
                count($missingIncluded).' database(s) disappeared from '.$connection->name,
                [
                    'These included databases are no longer on the server. Their backup history is kept:',
                    implode(', ', $missingIncluded),
                ],
                $url,
            );
        }
    }
}
