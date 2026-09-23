<?php

declare(strict_types=1);

namespace App\Services\Restore;

use App\Enums\BackupFileStatus;
use App\Enums\CopyStatus;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Exceptions\BackupException;
use App\Models\BackupFile;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Models\Database;
use App\Models\Destination;
use App\Services\Backup\BackupCreator;
use App\Services\Backup\CopyUploader;
use App\Services\Backup\RunFinalizer;
use App\Support\JobLog;
use App\Support\WorkDir;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Takes a "pre_restore" backup of a database that is about to be replaced.
 * Runs inline in the restore job, which already holds the database lock.
 */
class SafetyBackup
{
    public function __construct(
        private readonly BackupCreator $creator,
        private readonly CopyUploader $uploader,
        private readonly RunFinalizer $finalizer,
    ) {}

    /**
     * @throws BackupException when no safe copy could be stored
     */
    public function take(Database $database, ?int $userId, JobLog $log): BackupFile
    {
        $run = BackupRun::query()->create([
            'connection_id' => $database->connection_id,
            'trigger' => RunTrigger::PreRestore,
            'status' => RunStatus::Running,
            'started_at' => now(),
            'triggered_by' => $userId,
        ]);
        $file = BackupFile::query()->create([
            'backup_run_id' => $run->id,
            'database_id' => $database->id,
            'status' => BackupFileStatus::Running,
        ]);
        $file->setRelation('database', $database);
        $file->setRelation('run', $run);

        $artifact = null;
        try {
            $artifact = $this->creator->create($file, $log);
            $file->forceFill([
                'filename' => $artifact->filename,
                'size_bytes' => $artifact->sizeBytes,
                'sha256' => $artifact->sha256,
                'md5' => $artifact->md5,
                'duration_ms' => $artifact->durationMs,
                'manifest' => $artifact->manifest,
            ])->save();

            $stored = 0;
            foreach ($this->destinations($database) as $destination) {
                $copy = $this->uploader->upload($file, $artifact, $destination, $log);
                if (in_array($copy->status, [CopyStatus::Uploaded, CopyStatus::Verified], true)) {
                    $stored++;
                }
            }

            if ($stored === 0) {
                throw new BackupException('The safety backup could not be stored on any destination.');
            }

            $file->forceFill(['status' => BackupFileStatus::Success, 'log' => $log->text()])->save();
            WorkDir::delete($artifact->path);
            WorkDir::delete($artifact->manifestPath);
        } catch (Throwable $e) {
            $file->forceFill(['status' => BackupFileStatus::Failed, 'error' => $e->getMessage(), 'log' => $log->text()])->save();
            // An encrypted artifact that no destination accepted stays in staging for manual recovery.
            $this->finalizer->finalizeIfComplete($run);

            throw $e instanceof BackupException ? $e : new BackupException('Safety backup failed: '.$e->getMessage(), 0, $e);
        }

        $this->finalizer->finalizeIfComplete($run);

        return $file;
    }

    /**
     * Destinations of active plans that cover the database, or every active destination.
     *
     * @return Collection<int, Destination>
     */
    private function destinations(Database $database): Collection
    {
        $planIds = BackupPlan::query()
            ->where('is_active', true)
            ->where('connection_id', $database->connection_id)
            ->where(fn ($q) => $q->where('all_included_databases', true)
                ->orWhereHas('databases', fn ($d) => $d->where('databases.id', $database->id)))
            ->pluck('id');

        $fromPlans = Destination::query()->where('is_active', true)
            ->whereHas('backupPlans', fn ($q) => $q->whereIn('backup_plans.id', $planIds))
            ->orderBy('id')->get();

        return $fromPlans->isNotEmpty() ? $fromPlans : Destination::query()->where('is_active', true)->orderBy('id')->get();
    }
}
