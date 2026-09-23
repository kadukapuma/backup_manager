<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BackupFileStatus;
use App\Models\BackupFile;
use App\Services\Backup\RunFinalizer;
use Illuminate\Console\Command;

/**
 * Marks backup files as failed when their job vanished (worker killed hard,
 * server rebooted) so runs never stay "running" forever.
 */
class ReapStuckBackups extends Command
{
    protected $signature = 'backup-manager:reap-stuck';

    protected $description = 'Fail backup files whose jobs stopped without reporting';

    public function handle(RunFinalizer $finalizer): int
    {
        $timeout = (int) config('backup-manager.timeouts.backup');
        $waiting = (int) config('backup-manager.lock_wait_attempts') * (int) config('backup-manager.lock_wait_seconds');
        $cutoff = now()->subSeconds($timeout + $waiting + 3600);

        $stuck = BackupFile::query()
            ->with('run')
            ->whereIn('status', [BackupFileStatus::Queued->value, BackupFileStatus::Running->value])
            ->where('updated_at', '<', $cutoff)
            ->get();

        foreach ($stuck as $file) {
            $file->forceFill([
                'status' => BackupFileStatus::Failed,
                'error' => 'The backup job stopped without reporting (worker restart or crash).',
            ])->save();
            $finalizer->finalizeIfComplete($file->run);
        }

        if ($stuck->isNotEmpty()) {
            $this->warn("Marked {$stuck->count()} stuck backup file(s) as failed.");
        }

        return self::SUCCESS;
    }
}
