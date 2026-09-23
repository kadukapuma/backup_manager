<?php

declare(strict_types=1);

namespace App\Actions\Plans;

use App\Data\RetentionPolicy;
use App\Enums\AuditAction;
use App\Models\BackupPlan;
use App\Services\Audit\AuditLogger;
use App\Support\CronSchedule;
use Illuminate\Support\Facades\DB;

class SaveBackupPlan
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function handle(array $data, ?BackupPlan $plan = null): BackupPlan
    {
        $isNew = $plan === null;
        $plan ??= new BackupPlan;

        DB::transaction(function () use ($plan, $data): void {
            $plan->fill([
                'name' => $data['name'],
                'connection_id' => (int) $data['connection_id'],
                'cron_expression' => $data['cron_expression'],
                'timezone' => $data['timezone'],
                // Phase 1: zstd compression and age encryption are mandatory.
                'compression' => 'zstd',
                'encrypt' => true,
                'retention' => RetentionPolicy::fromArray($data['retention'])->toArray(),
                'all_included_databases' => (bool) $data['all_included_databases'],
                'is_active' => (bool) $data['is_active'],
            ]);
            $plan->next_run_at = CronSchedule::next($plan->cron_expression, $plan->timezone);
            $plan->save();

            $plan->destinations()->sync(array_map('intval', $data['destination_ids']));
            $plan->databases()->sync($plan->all_included_databases ? [] : array_map('intval', $data['database_ids'] ?? []));
        });

        $this->audit->log($isNew ? AuditAction::PlanCreated : AuditAction::PlanUpdated, $plan, [
            'name' => $plan->name,
            'cron' => $plan->cron_expression,
            'retention' => $plan->retention,
            'destinations' => $plan->destinations()->pluck('name')->all(),
        ]);

        return $plan;
    }
}
