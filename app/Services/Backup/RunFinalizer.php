<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Enums\AuditAction;
use App\Enums\BackupFileStatus;
use App\Enums\CopyStatus;
use App\Enums\NotificationEvent;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\BackupCopy;
use App\Models\BackupFile;
use App\Models\BackupRun;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\Cache;

/**
 * Closes a run once every file has finished: sets success/partial/failed,
 * writes the summary, audit entry and notifications.
 */
class RunFinalizer
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    public function finalizeIfComplete(BackupRun $run): bool
    {
        return (bool) Cache::lock('bm:run-finalize:'.$run->id, 30)->block(10, function () use ($run): bool {
            $run->refresh();
            if ($run->status->isFinished()) {
                return false;
            }

            $files = $run->files()->with(['copies', 'database'])->get();
            $open = $files->contains(fn (BackupFile $f): bool => in_array($f->status, [BackupFileStatus::Queued, BackupFileStatus::Running], true));
            if ($open) {
                return false;
            }

            $this->finalize($run, $files->all());

            return true;
        });
    }

    /**
     * @param  list<BackupFile>  $files
     */
    private function finalize(BackupRun $run, array $files): void
    {
        $ok = array_filter($files, fn (BackupFile $f): bool => $f->status === BackupFileStatus::Success);
        $failedFiles = array_filter($files, fn (BackupFile $f): bool => $f->status === BackupFileStatus::Failed);
        $copies = collect($files)->flatMap(fn (BackupFile $f) => $f->copies)->all();
        $failedCopies = array_filter($copies, fn ($c): bool => $c->status === CopyStatus::Failed);

        $status = match (true) {
            $files === [] => RunStatus::Success,
            $ok === [] => RunStatus::Failed,
            $failedFiles !== [] || $failedCopies !== [] => RunStatus::Partial,
            default => RunStatus::Success,
        };

        $started = $run->started_at ?? $run->created_at;
        $run->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'summary' => [
                ...($run->summary ?? []),
                'databases' => count($files),
                'succeeded' => count($ok),
                'failed' => count($failedFiles),
                'copies' => count($copies),
                'copies_failed' => count($failedCopies),
                'bytes' => array_sum(array_map(fn (BackupFile $f): int => (int) $f->size_bytes, $ok)),
                'duration_seconds' => $started !== null ? (int) $started->diffInSeconds(now(), true) : null,
                'failed_databases' => array_values(array_map(fn (BackupFile $f): string => $f->database->name, $failedFiles)),
            ],
        ])->save();

        $this->audit->log(AuditAction::BackupRunFinished, $run, [
            'status' => $status->value,
            'trigger' => $run->trigger->value,
            'plan' => $run->plan?->name,
        ], $run->triggered_by);

        if ($run->trigger !== RunTrigger::PreRestore) {
            $this->notify($run, $status, $failedFiles, $failedCopies);
        }
    }

    /**
     * @param  array<int, BackupFile>  $failedFiles
     * @param  array<int, BackupCopy>  $failedCopies
     */
    private function notify(BackupRun $run, RunStatus $status, array $failedFiles, array $failedCopies): void
    {
        $label = $run->plan?->name ?? 'Manual backup';
        $url = url('/runs/'.$run->id);
        $summary = $run->summary ?? [];

        $lines = [
            "Plan: {$label}",
            "Databases: {$summary['succeeded']} of {$summary['databases']} succeeded.",
        ];
        foreach (array_slice($failedFiles, 0, 20) as $file) {
            $lines[] = "✗ {$file->database->name}: ".($file->error ?? 'failed');
        }
        foreach (array_slice($failedCopies, 0, 20) as $copy) {
            $lines[] = "✗ copy to destination #{$copy->destination_id}: ".($copy->error ?? 'failed');
        }

        match ($status) {
            RunStatus::Failed => $this->notifier->send(NotificationEvent::RunFailed, "Backup FAILED: {$label}", $lines, $url),
            RunStatus::Partial => $this->notifier->send(NotificationEvent::RunPartial, "Backup partially failed: {$label}", $lines, $url),
            default => $this->notifier->send(NotificationEvent::RunSuccess, "Backup succeeded: {$label}", $lines, $url),
        };
    }
}
