<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BackupFileStatus;
use App\Enums\RestoreStatus;
use App\Models\BackupFile;
use App\Models\RestoreJob;
use App\Services\Backup\RunFinalizer;
use Illuminate\Console\Command;

/**
 * Marks backups and restores as failed when their job vanished (worker killed
 * hard, server rebooted), and wipes pasted age keys that were never used.
 */
class ReapStuckBackups extends Command
{
    protected $signature = 'backup-manager:reap-stuck';

    protected $description = 'Fail backup/restore jobs that stopped without reporting and wipe stale age keys';

    public function handle(RunFinalizer $finalizer): int
    {
        $waiting = (int) config('backup-manager.lock_wait_attempts') * (int) config('backup-manager.lock_wait_seconds');

        $backupCutoff = now()->subSeconds((int) config('backup-manager.timeouts.backup') + $waiting + 3600);
        $stuck = BackupFile::query()
            ->with('run')
            ->whereIn('status', [BackupFileStatus::Queued->value, BackupFileStatus::Running->value])
            ->where('updated_at', '<', $backupCutoff)
            ->get();

        foreach ($stuck as $file) {
            $file->forceFill([
                'status' => BackupFileStatus::Failed,
                'error' => 'The backup job stopped without reporting (worker restart or crash).',
            ])->save();
            $finalizer->finalizeIfComplete($file->run);
        }

        $restoreCutoff = now()->subSeconds((int) config('backup-manager.timeouts.restore') + $waiting + 3600);
        $stuckRestores = RestoreJob::query()
            ->whereNotIn('status', [RestoreStatus::Success->value, RestoreStatus::Failed->value])
            ->where('updated_at', '<', $restoreCutoff)
            ->update([
                'status' => RestoreStatus::Failed->value,
                'error' => 'The restore job stopped without reporting (worker restart or crash).',
                'age_identity' => null,
                'finished_at' => now(),
            ]);

        // A pasted private key must never outlive its restore request.
        $wiped = RestoreJob::query()
            ->whereNotNull('age_identity')
            ->where('created_at', '<', now()->subHour())
            ->update(['age_identity' => null]);

        if ($stuck->isNotEmpty() || $stuckRestores > 0 || $wiped > 0) {
            $this->warn("Failed {$stuck->count()} backup file(s) and {$stuckRestores} restore(s); wiped {$wiped} unused key(s).");
        }

        return self::SUCCESS;
    }
}
