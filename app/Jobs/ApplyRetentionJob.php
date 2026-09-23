<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Database;
use App\Services\Retention\RetentionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies retention for the given databases (all databases when empty).
 */
class ApplyRetentionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * @param  list<int>  $databaseIds
     */
    public function __construct(public readonly array $databaseIds = [])
    {
        $this->onQueue((string) config('backup-manager.queues.default'));
    }

    public function handle(RetentionService $retention): void
    {
        Database::query()
            ->when($this->databaseIds !== [], fn ($q) => $q->whereIn('id', $this->databaseIds))
            ->orderBy('id')
            ->each(fn (Database $db) => $retention->apply($db));
    }
}
