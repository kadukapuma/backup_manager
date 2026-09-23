<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Models\Destination;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\CatalogRebuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RebuildCatalogJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $destinationId, public readonly ?int $userId = null)
    {
        $this->onQueue((string) config('backup-manager.queues.default'));
    }

    public function uniqueId(): string
    {
        return 'rebuild-catalog:'.$this->destinationId;
    }

    public function handle(CatalogRebuilder $rebuilder, AuditLogger $audit): void
    {
        $destination = Destination::query()->find($this->destinationId);
        if ($destination === null) {
            return;
        }

        $result = $rebuilder->rebuild($destination, $this->userId);

        $audit->log(AuditAction::CatalogRebuilt, $destination, [
            'destination' => $destination->name,
            'manifests' => $result['manifests'],
            'files_created' => $result['files_created'],
            'copies_linked' => $result['copies_linked'],
            'skipped' => $result['skipped'],
            'run_id' => $result['run_id'],
        ], $this->userId);
    }
}
