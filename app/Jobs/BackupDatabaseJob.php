<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BackupFileStatus;
use App\Enums\CopyStatus;
use App\Enums\RunStatus;
use App\Models\BackupFile;
use App\Models\Destination;
use App\Services\Backup\BackupCreator;
use App\Services\Backup\CopyUploader;
use App\Services\Backup\RunFinalizer;
use App\Support\JobLog;
use App\Support\WorkDir;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Backs up one database of a run: dump, verify, encrypt, upload to every
 * destination, verify remotely. Holds the per-database lock throughout.
 */
class BackupDatabaseJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $tries;

    public bool $failOnTimeout = true;

    /**
     * @param  list<int>  $destinationIds
     */
    public function __construct(public readonly int $backupFileId, public readonly array $destinationIds)
    {
        $this->onQueue((string) config('backup-manager.queues.backups'));
        $this->timeout = (int) config('backup-manager.timeouts.backup');
        // Releases while waiting for the database lock count as attempts.
        $this->tries = (int) config('backup-manager.lock_wait_attempts') + 1;
    }

    public function handle(BackupCreator $creator, CopyUploader $uploader, RunFinalizer $finalizer): void
    {
        $file = BackupFile::query()->with(['database.serverConnection', 'run'])->find($this->backupFileId);
        if ($file === null || ! in_array($file->status, [BackupFileStatus::Queued, BackupFileStatus::Running], true)) {
            return;
        }

        $lock = Cache::lock($file->database->lockKey(), (int) config('backup-manager.lock_seconds'));

        if (! $lock->get()) {
            if ($this->attempts() < $this->tries) {
                $this->release((int) config('backup-manager.lock_wait_seconds'));

                return;
            }

            $this->markFailed($file, 'Another backup or restore of this database was still running.');
            $finalizer->finalizeIfComplete($file->run);

            return;
        }

        try {
            $this->process($file, $creator, $uploader);
        } finally {
            $lock->release();
        }

        $finalizer->finalizeIfComplete($file->run);
    }

    private function process(BackupFile $file, BackupCreator $creator, CopyUploader $uploader): void
    {
        $log = new JobLog([$file->database->serverConnection->password]);
        $artifact = null;

        $file->forceFill(['status' => BackupFileStatus::Running, 'error' => null])->save();
        if ($file->run->status === RunStatus::Queued) {
            $file->run->forceFill(['status' => RunStatus::Running, 'started_at' => now()])->save();
        }

        try {
            $artifact = $creator->create($file, $log);

            $file->forceFill([
                'filename' => $artifact->filename,
                'size_bytes' => $artifact->sizeBytes,
                'sha256' => $artifact->sha256,
                'md5' => $artifact->md5,
                'duration_ms' => $artifact->durationMs,
                'manifest' => $artifact->manifest,
            ])->save();

            $destinations = Destination::query()->whereIn('id', $this->destinationIds)->where('is_active', true)->orderBy('id')->get();
            $stored = 0;
            foreach ($destinations as $destination) {
                $log->addSecrets(array_map('strval', array_values(array_filter(
                    $destination->config,
                    fn ($v, $k): bool => in_array($k, $destination->type->secretKeys(), true),
                    ARRAY_FILTER_USE_BOTH,
                ))));
                $copy = $uploader->upload($file, $artifact, $destination, $log);
                if (in_array($copy->status, [CopyStatus::Uploaded, CopyStatus::Verified], true)) {
                    $stored++;
                }
            }

            if ($stored === 0) {
                $log->add('No destination accepted the file. The encrypted file is kept in staging: '.$artifact->path);
                $this->finish($file, BackupFileStatus::Failed, 'No destination accepted the backup (kept in staging).', $log);

                return;
            }

            WorkDir::delete($artifact->path);
            WorkDir::delete($artifact->manifestPath);
            $log->add("Stored on {$stored} of {$destinations->count()} destination(s).");

            $this->finish($file, BackupFileStatus::Success, null, $log);
            $file->database->forceFill(['last_success_at' => now()])->save();
        } catch (Throwable $e) {
            $message = Str::limit($e->getMessage(), 1000);
            $log->add('FAILED: '.$message);
            if ($artifact !== null) {
                WorkDir::delete($artifact->path);
                WorkDir::delete($artifact->manifestPath);
            }
            $this->finish($file, BackupFileStatus::Failed, $message, $log);
        }
    }

    private function finish(BackupFile $file, BackupFileStatus $status, ?string $error, JobLog $log): void
    {
        $file->forceFill(['status' => $status, 'error' => $error, 'log' => $log->text()])->save();
    }

    private function markFailed(BackupFile $file, string $message): void
    {
        $file->forceFill(['status' => BackupFileStatus::Failed, 'error' => $message])->save();
    }

    /**
     * Timeouts and worker crashes land here.
     */
    public function failed(?Throwable $exception): void
    {
        $file = BackupFile::query()->with(['run', 'database'])->find($this->backupFileId);
        if ($file === null || ! in_array($file->status, [BackupFileStatus::Queued, BackupFileStatus::Running], true)) {
            return;
        }

        // A running file means this job held the lock when it died (timeout/crash).
        if ($file->status === BackupFileStatus::Running) {
            Cache::lock($file->database->lockKey())->forceRelease();
        }

        $this->markFailed($file, 'Job aborted: '.Str::limit($exception?->getMessage() ?? 'unknown error', 500));
        app(RunFinalizer::class)->finalizeIfComplete($file->run);
    }
}
