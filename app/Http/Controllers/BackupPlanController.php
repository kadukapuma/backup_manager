<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Plans\SaveBackupPlan;
use App\Enums\AuditAction;
use App\Enums\DatabaseState;
use App\Http\Requests\Plans\SaveBackupPlanRequest;
use App\Models\BackupPlan;
use App\Models\Database;
use App\Models\Destination;
use App\Models\ServerConnection;
use App\Services\Audit\AuditLogger;
use App\Support\CronSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BackupPlanController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('viewAny', BackupPlan::class);

        $plans = BackupPlan::query()
            ->with(['serverConnection:id,name', 'destinations:id,name', 'databases:id,name', 'runs' => fn ($q) => $q->latest('id')->limit(1)])
            ->orderBy('name')
            ->get()
            ->map(fn (BackupPlan $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'connection_id' => $p->connection_id,
                'connection_name' => $p->serverConnection->name,
                'cron_expression' => $p->cron_expression,
                'timezone' => $p->timezone,
                'retention' => $p->retentionPolicy()->toArray(),
                'all_included_databases' => $p->all_included_databases,
                'database_ids' => $p->databases->pluck('id')->all(),
                'database_names' => $p->databases->pluck('name')->all(),
                'destination_ids' => $p->destinations->pluck('id')->all(),
                'destination_names' => $p->destinations->pluck('name')->all(),
                'target_count' => $p->targetDatabases()->count(),
                'is_active' => $p->is_active,
                'last_run_at' => $p->last_run_at?->toIso8601String(),
                'next_run_at' => $p->is_active ? $p->next_run_at?->toIso8601String() : null,
                'last_run_status' => $p->runs->first()?->status->value,
                'last_run_id' => $p->runs->first()?->id,
            ]);

        return Inertia::render('plans/index', [
            'plans' => $plans,
            'connections' => ServerConnection::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (ServerConnection $c): array => ['value' => (string) $c->id, 'label' => $c->name]),
            'destinations' => Destination::query()->orderBy('name')->get()
                ->map(fn (Destination $d): array => ['value' => (string) $d->id, 'label' => $d->name.' ('.$d->type->label().')'.($d->is_active ? '' : ' – inactive')]),
            'databases' => Database::query()->whereNull('missing_since')->where('state', DatabaseState::Included->value)
                ->orderBy('name')->get(['id', 'name', 'connection_id'])
                ->map(fn (Database $d): array => ['id' => $d->id, 'name' => $d->name, 'connection_id' => $d->connection_id]),
            'presets' => array_values(array_map(
                fn (array $p): array => ['value' => $p['expression'], 'label' => $p['label']],
                CronSchedule::PRESETS,
            )),
            'defaultTimezone' => config('app.timezone'),
        ]);
    }

    public function store(SaveBackupPlanRequest $request, SaveBackupPlan $action): RedirectResponse
    {
        $action->handle($request->validated());

        return back()->with('success', 'Backup plan created.');
    }

    public function update(SaveBackupPlanRequest $request, BackupPlan $plan, SaveBackupPlan $action): RedirectResponse
    {
        $action->handle($request->validated(), $plan);

        return back()->with('success', 'Backup plan updated.');
    }

    public function destroy(BackupPlan $plan): RedirectResponse
    {
        $this->authorize('delete', $plan);

        $this->audit->log(AuditAction::PlanDeleted, $plan, ['name' => $plan->name]);
        $plan->delete();

        return back()->with('success', 'Backup plan deleted. Existing backups and their history are kept.');
    }

    /**
     * Next run times for the cron helper.
     */
    public function cronPreview(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BackupPlan::class);

        $expression = preg_replace('/\s+/', ' ', trim((string) $request->query('expression', '')));
        $timezone = (string) $request->query('timezone', config('app.timezone'));

        if (! CronSchedule::isValid((string) $expression) || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return response()->json(['valid' => false, 'runs' => []]);
        }

        try {
            $runs = array_map(fn ($d) => $d->toIso8601String(), CronSchedule::nextRuns((string) $expression, $timezone, 5));
        } catch (Throwable) {
            return response()->json(['valid' => false, 'runs' => []]);
        }

        return response()->json(['valid' => true, 'runs' => $runs]);
    }
}
