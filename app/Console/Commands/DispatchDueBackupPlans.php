<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Backups\StartBackupRun;
use App\Enums\NotificationEvent;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Exceptions\BackupException;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Services\Notifications\Notifier;
use App\Support\CronSchedule;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs every minute from the scheduler. A plan that was due while the server
 * was down runs once, not once per missed slot.
 */
class DispatchDueBackupPlans extends Command
{
    protected $signature = 'backup-manager:dispatch-due';

    protected $description = 'Start backup runs for plans whose next run time has passed';

    public function handle(StartBackupRun $start, Notifier $notifier): int
    {
        $plans = BackupPlan::query()
            ->with(['serverConnection', 'destinations'])
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->get();

        foreach ($plans as $plan) {
            // Move the schedule forward first so a crash cannot cause a loop of runs.
            $plan->forceFill([
                'last_run_at' => now(),
                'next_run_at' => CronSchedule::next($plan->cron_expression, $plan->timezone),
            ])->save();

            try {
                $run = $start->handle(
                    $plan->serverConnection,
                    $plan->targetDatabases(),
                    $plan->destinations,
                    RunTrigger::Scheduled,
                    $plan,
                );
                $this->info("Plan {$plan->name}: run #{$run->id} started.");
            } catch (Throwable $e) {
                $message = $e instanceof BackupException ? $e->getMessage() : 'Unexpected error: '.$e->getMessage();
                $run = BackupRun::query()->create([
                    'backup_plan_id' => $plan->id,
                    'connection_id' => $plan->connection_id,
                    'trigger' => RunTrigger::Scheduled,
                    'status' => RunStatus::Failed,
                    'started_at' => now(),
                    'finished_at' => now(),
                    'summary' => ['error' => $message, 'databases' => 0, 'succeeded' => 0],
                ]);
                $notifier->send(NotificationEvent::RunFailed, "Backup FAILED to start: {$plan->name}", [$message], url('/runs/'.$run->id));
                $this->error("Plan {$plan->name}: {$message}");
            }
        }

        return self::SUCCESS;
    }
}
