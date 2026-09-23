<?php

declare(strict_types=1);

namespace App\Services\Retention;

use App\Data\RetentionPolicy;
use App\Enums\AuditAction;
use App\Enums\BackupFileStatus;
use App\Enums\CopyStatus;
use App\Models\BackupCopy;
use App\Models\BackupPlan;
use App\Models\Database;
use App\Models\Destination;
use App\Services\Audit\AuditLogger;
use App\Services\Storage\RcloneClient;
use App\Support\BackupFilename;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Applies GFS retention per database and destination. The policy for a pair
 * comes from the active plans that cover the database and use the
 * destination (the most generous counts win). Without such a plan nothing is
 * deleted.
 */
class RetentionService
{
    public function __construct(
        private readonly RcloneClient $rclone,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return int number of copies deleted; -1 when the database is busy
     */
    public function apply(Database $database): int
    {
        $lock = Cache::lock($database->lockKey(), 3600);
        if (! $lock->get()) {
            return -1;
        }

        try {
            $deleted = 0;
            $details = [];

            foreach ($this->policies($database) as $destinationId => $policy) {
                $destination = Destination::query()->find($destinationId);
                if ($destination === null) {
                    continue;
                }

                $copies = BackupCopy::query()
                    ->with('backupFile')
                    ->where('destination_id', $destinationId)
                    ->whereIn('status', [CopyStatus::Uploaded->value, CopyStatus::Verified->value])
                    ->whereHas('backupFile', fn ($q) => $q->where('database_id', $database->id)->where('status', BackupFileStatus::Success->value))
                    ->get()
                    ->keyBy('id');

                $dates = $copies->map(fn (BackupCopy $c) => $c->backupFile->created_at)->all();
                $toDelete = GfsRetentionCalculator::prune($dates, $policy, now(), (string) config('app.timezone'));

                foreach ($toDelete as $copyId) {
                    $copy = $copies[$copyId];
                    if ($this->deleteCopy($copy, $destination)) {
                        $deleted++;
                        $details[] = ['file' => $copy->backupFile->filename, 'destination' => $destination->name];
                    }
                }
            }

            if ($deleted > 0) {
                $this->audit->log(AuditAction::RetentionApplied, $database, [
                    'database' => $database->name,
                    'deleted' => $deleted,
                    'copies' => array_slice($details, 0, 100),
                ]);
            }

            return $deleted;
        } finally {
            $lock->release();
        }
    }

    /**
     * Deletes the file and its manifest from the destination and marks the copy deleted.
     */
    public function deleteCopy(BackupCopy $copy, Destination $destination): bool
    {
        try {
            $this->rclone->delete($destination, $copy->remote_path);
            $this->rclone->delete($destination, $copy->remote_path.BackupFilename::MANIFEST_SUFFIX);
        } catch (Throwable $e) {
            $copy->forceFill(['error' => 'Retention delete failed: '.Str::limit($e->getMessage(), 500)])->save();
            Log::warning('Retention delete failed', ['copy' => $copy->id, 'error' => $e->getMessage()]);

            return false;
        }

        $copy->forceFill(['status' => CopyStatus::Deleted, 'deleted_at' => now(), 'error' => null])->save();

        return true;
    }

    /**
     * @return array<int, RetentionPolicy> destination id => merged policy
     */
    public function policies(Database $database): array
    {
        $plans = BackupPlan::query()
            ->with('destinations:id')
            ->where('is_active', true)
            ->where('connection_id', $database->connection_id)
            ->where(fn ($q) => $q->where('all_included_databases', true)
                ->orWhereHas('databases', fn ($d) => $d->where('databases.id', $database->id)))
            ->get();

        $merged = [];
        foreach ($plans as $plan) {
            $policy = $plan->retentionPolicy();
            foreach ($plan->destinations as $destination) {
                $current = $merged[$destination->id] ?? null;
                $merged[$destination->id] = $current === null ? $policy : new RetentionPolicy(
                    max($current->keepDaily, $policy->keepDaily),
                    max($current->keepWeekly, $policy->keepWeekly),
                    max($current->keepMonthly, $policy->keepMonthly),
                );
            }
        }

        return $merged;
    }
}
