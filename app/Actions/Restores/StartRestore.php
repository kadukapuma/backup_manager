<?php

declare(strict_types=1);

namespace App\Actions\Restores;

use App\Enums\AuditAction;
use App\Enums\RestoreMode;
use App\Enums\RestoreStatus;
use App\Jobs\RestoreDatabaseJob;
use App\Models\BackupFile;
use App\Models\RestoreJob;
use App\Models\User;
use App\Services\Audit\AuditLogger;

class StartRestore
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(
        BackupFile $file,
        int $sourceCopyId,
        int $targetConnectionId,
        string $targetDatabase,
        RestoreMode $mode,
        ?string $identity,
        User $user,
    ): RestoreJob {
        $copy = $file->copies()->with('destination')->findOrFail($sourceCopyId);

        $restore = RestoreJob::query()->create([
            'backup_file_id' => $file->id,
            'source_destination_id' => $copy->destination_id,
            'target_connection_id' => $targetConnectionId,
            'target_database' => $targetDatabase,
            'mode' => $mode,
            'status' => RestoreStatus::Queued,
            'progress_message' => 'Waiting for a worker…',
            'requested_by' => $user->id,
            // Encrypted at rest; wiped as soon as the job starts (see RestoreDatabaseJob).
            'age_identity' => $identity,
        ]);

        $this->audit->log(AuditAction::RestoreRequested, $restore, [
            'backup' => $file->filename,
            'source_database' => $file->database->name,
            'source_destination' => $copy->destination->name,
            'target_connection_id' => $targetConnectionId,
            'target_database' => $targetDatabase,
            'mode' => $mode->value,
            'identity_source' => $identity !== null ? 'pasted' : 'server file',
        ], $user);

        RestoreDatabaseJob::dispatch($restore->id);

        return $restore;
    }
}
