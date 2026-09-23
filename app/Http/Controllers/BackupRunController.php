<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\BackupCopy;
use App\Models\BackupFile;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Models\VerificationRun;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BackupRunController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', BackupRun::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(RunStatus::class)],
            'trigger' => ['nullable', Rule::enum(RunTrigger::class)],
            'plan' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $runs = BackupRun::query()
            ->with(['plan:id,name', 'serverConnection:id,name', 'triggeredBy:id,name'])
            ->withCount('files')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['trigger'] ?? null, fn ($q, $v) => $q->where('trigger', $v))
            ->when($filters['plan'] ?? null, fn ($q, $v) => $q->where('backup_plan_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (BackupRun $r): array => self::presentRun($r));

        return Inertia::render('runs/index', [
            'runs' => $runs,
            'filters' => [
                'status' => $filters['status'] ?? '',
                'trigger' => $filters['trigger'] ?? '',
                'plan' => isset($filters['plan']) ? (string) $filters['plan'] : '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'statuses' => RunStatus::options(),
            'triggers' => RunTrigger::options(),
            'plans' => BackupPlan::query()->orderBy('name')->get(['id', 'name'])->map(fn (BackupPlan $p): array => ['value' => (string) $p->id, 'label' => $p->name]),
            'hasActive' => BackupRun::query()->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])->exists(),
        ]);
    }

    public function show(BackupRun $run): Response
    {
        $this->authorize('view', $run);

        $run->load(['plan:id,name', 'serverConnection:id,name', 'triggeredBy:id,name', 'files.database:id,name', 'files.copies.destination:id,name,type', 'files.verifications']);

        return Inertia::render('runs/show', [
            'run' => self::presentRun($run),
            'files' => $run->files->sortBy(fn (BackupFile $f) => $f->database->name)->values()->map(fn (BackupFile $f): array => [
                'id' => $f->id,
                'database' => $f->database->name,
                'database_id' => $f->database_id,
                'status' => $f->status->value,
                'filename' => $f->filename,
                'size_bytes' => $f->size_bytes,
                'sha256' => $f->sha256,
                'duration_ms' => $f->duration_ms,
                'error' => $f->error,
                'log' => $f->log,
                'copies' => $f->copies->map(fn (BackupCopy $c): array => [
                    'id' => $c->id,
                    'destination' => $c->destination->name,
                    'destination_type' => $c->destination->type->value,
                    'remote_path' => $c->remote_path,
                    'status' => $c->status->value,
                    'verified_at' => $c->verified_at?->toIso8601String(),
                    'error' => $c->error,
                ])->all(),
                'verifications' => $f->verifications->map(fn (VerificationRun $v): array => [
                    'level' => $v->level->value,
                    'status' => $v->status->value,
                    'details' => $v->details,
                    'created_at' => $v->created_at?->toIso8601String(),
                ])->all(),
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentRun(BackupRun $r): array
    {
        return [
            'id' => $r->id,
            'plan' => $r->plan?->name,
            'connection' => $r->serverConnection?->name,
            'trigger' => $r->trigger->value,
            'status' => $r->status->value,
            'triggered_by' => $r->triggeredBy?->name,
            'created_at' => $r->created_at->toIso8601String(),
            'started_at' => $r->started_at?->toIso8601String(),
            'finished_at' => $r->finished_at?->toIso8601String(),
            'files_count' => $r->files_count ?? $r->files->count(),
            'summary' => $r->summary ?? (object) [],
        ];
    }
}
