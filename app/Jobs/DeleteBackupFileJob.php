<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CopyStatus;
use App\Models\BackupFile;
use App\Services\Retention\RetentionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes every stored copy of a backup (admin action). The catalog rows stay
 * for history, with copies marked deleted.
 */
class DeleteBackupFileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $backupFileId)
    {
        $this->onQueue((string) config('backup-manager.queues.default'));
    }

    public function handle(RetentionService $retention): void
    {
        $file = BackupFile::query()->with('copies.destination')->find($this->backupFileId);
        if ($file === null) {
            return;
        }

        foreach ($file->copies as $copy) {
            if ($copy->status !== CopyStatus::Deleted && $copy->status !== CopyStatus::Pending) {
                $retention->deleteCopy($copy, $copy->destination);
            }
        }
    }
}
