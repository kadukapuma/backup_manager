<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Enums\AuditAction;
use App\Enums\BackupFileStatus;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Exceptions\BackupException;
use App\Jobs\BackupDatabaseJob;
use App\Models\BackupFile;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Models\Database;
use App\Models\Destination;
use App\Models\ServerConnection;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsStore;
use App\Support\DatabaseName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creates a run with one queued file per database and dispatches one job per
 * database. Used for scheduled and manual backups.
 */
class StartBackupRun
{
    public function __construct(
        private readonly SettingsStore $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  Collection<int, Database>  $databases
     * @param  Collection<int, Destination>  $destinations
     *
     * @throws BackupException when the run cannot start at all
     */
    public function handle(
        ServerConnection $connection,
        Collection $databases,
        Collection $destinations,
        RunTrigger $trigger,
        ?BackupPlan $plan = null,
        ?User $user = null,
    ): BackupRun {
        if ($this->settings->agePublicKey() === null) {
            throw new BackupException('No age public key is configured. Set it on the System settings page first.');
        }
        if (! $connection->is_active) {
            throw new BackupException("Connection {$connection->name} is inactive.");
        }

        $destinations = $destinations->filter(fn (Destination $d): bool => $d->is_active)->values();
        if ($destinations->isEmpty()) {
            throw new BackupException('No active destination to store the backup.');
        }

        $databases = $databases
            ->filter(fn (Database $d): bool => $d->connection_id === $connection->id && DatabaseName::isBackupable($d->name))
            ->values();

        $run = DB::transaction(function () use ($connection, $databases, $trigger, $plan, $user, $destinations): BackupRun {
            $run = BackupRun::query()->create([
                'backup_plan_id' => $plan?->id,
                'connection_id' => $connection->id,
                'trigger' => $trigger,
                'status' => $databases->isEmpty() ? RunStatus::Success : RunStatus::Queued,
                'triggered_by' => $user?->id,
                'finished_at' => $databases->isEmpty() ? now() : null,
                'summary' => [
                    'destinations' => $destinations->pluck('name')->all(),
                    ...($databases->isEmpty() ? ['message' => 'No included databases to back up.', 'databases' => 0] : []),
                ],
            ]);

            foreach ($databases as $database) {
                BackupFile::query()->create([
                    'backup_run_id' => $run->id,
                    'database_id' => $database->id,
                    'status' => BackupFileStatus::Queued,
                ]);
            }

            return $run;
        });

        $destinationIds = $destinations->pluck('id')->all();
        foreach ($run->files()->pluck('id') as $fileId) {
            BackupDatabaseJob::dispatch((int) $fileId, $destinationIds)->afterCommit();
        }

        $this->audit->log(AuditAction::BackupRunStarted, $run, [
            'trigger' => $trigger->value,
            'plan' => $plan?->name,
            'connection' => $connection->name,
            'databases' => $databases->pluck('name')->take(200)->all(),
        ], $user);

        return $run;
    }
}
