<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\CopyStatus;
use App\Enums\DestinationType;
use App\Enums\NotificationEvent;
use App\Enums\RestoreMode;
use App\Enums\RestoreStatus;
use App\Exceptions\BackupException;
use App\Models\BackupCopy;
use App\Models\Database;
use App\Models\RestoreJob;
use App\Services\Audit\AuditLogger;
use App\Services\Database\DatabaseServerClient;
use App\Services\Notifications\Notifier;
use App\Services\Restore\RestorePipeline;
use App\Services\Restore\SafetyBackup;
use App\Services\Storage\RcloneClient;
use App\Support\JobLog;
use App\Support\WorkDir;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Restores one backup file:
 * lock → safety backup (if the target exists) → download → SHA-256 check →
 * recreate database → age -d | zstd -dc | mariadb/psql → table-count post-check.
 */
class RestoreDatabaseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    private JobLog $log;

    public function __construct(public readonly int $restoreJobId)
    {
        $this->onQueue((string) config('backup-manager.queues.restores'));
        $this->timeout = (int) config('backup-manager.timeouts.restore');
    }

    public function handle(
        DatabaseServerClient $client,
        RcloneClient $rclone,
        RestorePipeline $pipeline,
        SafetyBackup $safety,
        AuditLogger $audit,
        Notifier $notifier,
    ): void {
        $restore = RestoreJob::query()->with(['backupFile.database.serverConnection', 'targetConnection'])->find($this->restoreJobId);
        if ($restore === null || $restore->status !== RestoreStatus::Queued) {
            return;
        }

        $connection = $restore->targetConnection;
        $file = $restore->backupFile;
        $this->log = new JobLog([$connection->password, $file->database->serverConnection->password]);

        // Take the pasted identity out of the database immediately.
        $pastedIdentity = $restore->age_identity;
        $restore->forceFill(['age_identity' => null, 'started_at' => now()])->save();

        $identityPath = null;
        $ownsIdentityFile = false;
        $encrypted = null;
        /** @var list<Lock> $locks */
        $locks = [];

        try {
            $locks = $this->acquireLocks($restore);

            [$identityPath, $ownsIdentityFile] = $this->identityFile($pastedIdentity);

            $existing = $client->databaseExists($connection, $restore->target_database);
            if ($existing) {
                $this->step($restore, RestoreStatus::SafetyBackup, 'Taking a safety backup of the current database…');
                $target = $this->catalogDatabase($restore);
                $safetyFile = $safety->take($target, $restore->requested_by, $this->log);
                $restore->forceFill(['safety_backup_file_id' => $safetyFile->id])->save();
                $this->log->add("Safety backup stored: {$safetyFile->filename}");
            } elseif ($restore->mode === RestoreMode::Replace) {
                $this->log->add('The original database does not exist any more; nothing to protect with a safety backup.');
            }

            $encrypted = $this->download($restore, $rclone);

            $this->step($restore, RestoreStatus::Restoring, 'Recreating the database and importing…');
            $manifest = $file->manifest ?? [];
            $pipeline->recreateDatabase(
                $connection,
                $restore->target_database,
                $manifest['default_charset'] ?? $file->database->default_charset,
                $manifest['default_collation'] ?? $file->database->default_collation,
                $this->log,
            );
            $pipeline->import($connection, $restore->target_database, $encrypted, $identityPath, $this->log);
            $this->log->add('Import finished.');

            $this->step($restore, RestoreStatus::PostCheck, 'Checking the restored database…');
            $expected = isset($manifest['table_count']) ? (int) $manifest['table_count'] : null;
            $actual = $client->tableCount($connection, $restore->target_database);
            $ok = $expected === null || $expected === $actual;
            $restore->forceFill(['post_check' => ['expected_tables' => $expected, 'actual_tables' => $actual, 'ok' => $ok]])->save();

            if (! $ok) {
                throw new BackupException("Post-check failed: expected {$expected} tables, found {$actual}.");
            }

            $this->log->add("Post-check passed: {$actual} tables.");
            $this->finish($restore, RestoreStatus::Success, null, $audit, $notifier);

            if ($restore->mode === RestoreMode::NewCopy) {
                DiscoverDatabasesJob::dispatch($connection->id);
            }
        } catch (Throwable $e) {
            $this->log->add('FAILED: '.$e->getMessage());
            $this->finish($restore, RestoreStatus::Failed, Str::limit($e->getMessage(), 1000), $audit, $notifier);
        } finally {
            if ($ownsIdentityFile) {
                WorkDir::delete($identityPath);
            }
            WorkDir::delete($encrypted);
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }

    /**
     * @return list<Lock>
     */
    private function acquireLocks(RestoreJob $restore): array
    {
        $seconds = (int) config('backup-manager.lock_seconds');
        $wait = (int) config('backup-manager.lock_wait_attempts') * (int) config('backup-manager.lock_wait_seconds');
        $source = $restore->backupFile->database;

        $keys = array_values(array_unique([
            Database::lockKeyFor($restore->target_connection_id, $restore->target_database),
            // Keeps retention from deleting the copy that is being read.
            $source->lockKey(),
        ]));

        $locks = [];
        foreach ($keys as $key) {
            $lock = Cache::lock($key, $seconds);
            $this->log->add('Waiting for other jobs on this database to finish…');
            if (! $lock->block(max(1, $wait))) {
                foreach ($locks as $held) {
                    $held->release();
                }

                throw new BackupException('Another backup or restore of this database is still running. Try again later.');
            }
            $locks[] = $lock;
        }

        return $locks;
    }

    /**
     * @return array{0: string, 1: bool} path, and whether it is a temporary copy
     */
    private function identityFile(?string $pasted): array
    {
        if ($pasted !== null && $pasted !== '') {
            $path = WorkDir::tempPath('age-identity', '.txt');
            WorkDir::writePrivate($path, $pasted."\n");

            return [$path, true];
        }

        $configured = (string) config('backup-manager.age_identity_file');
        if ($configured === '' || ! is_readable($configured)) {
            throw new BackupException('No age private key was provided and AGE_IDENTITY_FILE is not readable.');
        }

        return [$configured, false];
    }

    /**
     * The catalog row for the database being replaced (always the source database in replace mode).
     */
    private function catalogDatabase(RestoreJob $restore): Database
    {
        $source = $restore->backupFile->database;
        if ($source->connection_id === $restore->target_connection_id && $source->name === $restore->target_database) {
            return $source;
        }

        return Database::query()->firstOrCreate(
            ['connection_id' => $restore->target_connection_id, 'name' => $restore->target_database],
            ['state' => 'excluded', 'state_source' => 'manual', 'first_seen_at' => now(), 'last_seen_at' => now()],
        );
    }

    /**
     * Downloads the encrypted file and checks its SHA-256. Tries the chosen
     * copy first, then local copies, then any other copy.
     */
    private function download(RestoreJob $restore, RcloneClient $rclone): string
    {
        $file = $restore->backupFile;
        $copies = $file->copies()->with('destination')
            ->whereIn('status', [CopyStatus::Uploaded->value, CopyStatus::Verified->value])
            ->get()
            ->sortBy(fn (BackupCopy $c): int => match (true) {
                $c->destination_id === $restore->source_destination_id => 0,
                $c->destination->type === DestinationType::Local => 1,
                default => 2,
            })
            ->values();

        if ($copies->isEmpty()) {
            throw new BackupException('No stored copy of this backup is available.');
        }

        foreach ($copies as $copy) {
            $path = WorkDir::tempPath('restore', '.sql.zst.age');
            try {
                $this->step($restore, RestoreStatus::Downloading, "Downloading from {$copy->destination->name}…");
                $rclone->download($copy->destination, $copy->remote_path, $path);

                $this->step($restore, RestoreStatus::Verifying, 'Verifying SHA-256…');
                $hash = (string) hash_file('sha256', $path);
                if ($file->sha256 !== null && ! hash_equals($file->sha256, $hash)) {
                    throw new BackupException("Checksum mismatch for the copy on {$copy->destination->name}.");
                }

                $restore->forceFill(['source_destination_id' => $copy->destination_id])->save();
                $this->log->add("Downloaded and verified from {$copy->destination->name}.");

                return $path;
            } catch (Throwable $e) {
                WorkDir::delete($path);
                $this->log->add("Copy on {$copy->destination->name} unusable: ".Str::limit($e->getMessage(), 300));
            }
        }

        throw new BackupException('None of the stored copies could be downloaded and verified.');
    }

    private function step(RestoreJob $restore, RestoreStatus $status, string $message): void
    {
        $this->log->add($message);
        $restore->forceFill(['status' => $status, 'progress_message' => $message, 'log' => $this->log->text()])->save();
    }

    private function finish(RestoreJob $restore, RestoreStatus $status, ?string $error, AuditLogger $audit, Notifier $notifier): void
    {
        $restore->forceFill([
            'status' => $status,
            'error' => $error,
            'progress_message' => $status === RestoreStatus::Success ? 'Restore completed.' : 'Restore failed.',
            'finished_at' => now(),
            'log' => $this->log->text(),
        ])->save();

        $audit->log(AuditAction::RestoreFinished, $restore, [
            'status' => $status->value,
            'target_database' => $restore->target_database,
            'error' => $error,
        ], $restore->requested_by);

        $lines = [
            'Backup: '.($restore->backupFile->filename ?? '?'),
            'Target: '.$restore->targetConnection->name.' / '.$restore->target_database.' ('.$restore->mode->value.')',
        ];
        if ($error !== null) {
            $lines[] = 'Error: '.$error;
        }

        $notifier->send(
            $status === RestoreStatus::Success ? NotificationEvent::RestoreSuccess : NotificationEvent::RestoreFailed,
            $status === RestoreStatus::Success ? "Restore finished: {$restore->target_database}" : "Restore FAILED: {$restore->target_database}",
            $lines,
            url('/restores/'.$restore->id),
        );
    }

    public function failed(?Throwable $exception): void
    {
        $restore = RestoreJob::query()->find($this->restoreJobId);
        if ($restore === null || $restore->status->isFinished()) {
            return;
        }

        $restore->forceFill([
            'status' => RestoreStatus::Failed,
            'age_identity' => null,
            'error' => 'Restore job aborted: '.Str::limit($exception?->getMessage() ?? 'unknown error', 500),
            'finished_at' => now(),
        ])->save();

        Cache::lock(Database::lockKeyFor($restore->target_connection_id, $restore->target_database))->forceRelease();
    }
}
