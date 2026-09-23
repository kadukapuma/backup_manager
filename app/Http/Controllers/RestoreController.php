<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Restores\StartRestore;
use App\Enums\BackupFileStatus;
use App\Enums\CopyStatus;
use App\Enums\RestoreMode;
use App\Http\Requests\Restores\StartRestoreRequest;
use App\Models\BackupCopy;
use App\Models\BackupFile;
use App\Models\Database;
use App\Models\RestoreJob;
use App\Models\ServerConnection;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RestoreController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', RestoreJob::class);

        $restores = RestoreJob::query()
            ->with(['backupFile.database:id,name', 'targetConnection:id,name', 'requestedBy:id,name'])
            ->orderByDesc('id')
            ->paginate(30)
            ->through(fn (RestoreJob $r): array => self::present($r));

        return Inertia::render('restores/index', ['restores' => $restores]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', RestoreJob::class);

        $selected = $request->integer('database') ?: null;
        $database = $selected !== null ? Database::query()->with('serverConnection:id,name')->find($selected) : null;

        $databases = Database::query()
            ->with('serverConnection:id,name')
            ->whereHas('backupFiles', fn ($q) => $q->where('status', BackupFileStatus::Success->value))
            ->orderBy('name')
            ->get()
            ->map(fn (Database $d): array => [
                'id' => $d->id,
                'name' => $d->name,
                'connection' => $d->serverConnection->name,
                'missing' => $d->missing_since !== null,
            ]);

        $timeline = $database === null ? [] : BackupFile::query()
            ->with(['copies.destination:id,name,type', 'run:id,trigger'])
            ->where('database_id', $database->id)
            ->where('status', BackupFileStatus::Success->value)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn (BackupFile $f): array => [
                'id' => $f->id,
                'filename' => $f->filename,
                'created_at' => $f->created_at->toIso8601String(),
                'trigger' => $f->run->trigger->value,
                'size_bytes' => $f->size_bytes,
                'sha256' => $f->sha256,
                'table_count' => $f->manifest['table_count'] ?? null,
                'copies' => $f->copies->map(fn (BackupCopy $c): array => [
                    'id' => $c->id,
                    'destination' => $c->destination->name,
                    'destination_type' => $c->destination->type->value,
                    'status' => $c->status->value,
                    'restorable' => in_array($c->status, [CopyStatus::Uploaded, CopyStatus::Verified], true),
                ])->all(),
            ])->all();

        return Inertia::render('restores/create', [
            'databases' => $databases,
            'database' => $database === null ? null : [
                'id' => $database->id,
                'name' => $database->name,
                'connection_id' => $database->connection_id,
                'connection' => $database->serverConnection->name,
            ],
            'timeline' => $timeline,
            'preselectedFile' => $request->integer('file') ?: null,
            'connections' => ServerConnection::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (ServerConnection $c): array => ['value' => (string) $c->id, 'label' => $c->name]),
            'identityFileConfigured' => (string) config('backup-manager.age_identity_file') !== '',
            'suggestedName' => $database === null ? null : substr($database->name.'_restore_'.now()->format('Ymd'), 0, 64),
        ]);
    }

    public function store(StartRestoreRequest $request, StartRestore $action): RedirectResponse
    {
        $data = $request->validated();
        /** @var User $user */
        $user = $request->user();
        $file = BackupFile::query()->with('database')->findOrFail((int) $data['backup_file_id']);
        $this->authorize('restore', $file);

        $restore = $action->handle(
            $file,
            (int) $data['source_copy_id'],
            (int) $data['target_connection_id'],
            (string) $data['target_database'],
            RestoreMode::from((string) $data['mode']),
            $request->identity(),
            $user,
        );

        return redirect()->route('restores.show', $restore->id)->with('success', 'Restore queued.');
    }

    public function show(RestoreJob $restore): Response
    {
        $this->authorize('view', $restore);

        $restore->load(['backupFile.database:id,name', 'targetConnection:id,name', 'requestedBy:id,name', 'sourceDestination:id,name', 'safetyBackupFile:id,filename,backup_run_id']);

        return Inertia::render('restores/show', ['restore' => [
            ...self::present($restore),
            'log' => $restore->log,
            'post_check' => $restore->post_check,
            'source_destination' => $restore->sourceDestination?->name,
            'safety_backup' => $restore->safetyBackupFile ? [
                'filename' => $restore->safetyBackupFile->filename,
                'run_id' => $restore->safetyBackupFile->backup_run_id,
            ] : null,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(RestoreJob $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status->value,
            'progress_message' => $r->progress_message,
            'mode' => $r->mode->value,
            'source_database' => $r->backupFile->database->name,
            'backup_filename' => $r->backupFile->filename,
            'backup_created_at' => $r->backupFile->created_at->toIso8601String(),
            'target_connection' => $r->targetConnection->name,
            'target_database' => $r->target_database,
            'requested_by' => $r->requestedBy?->name,
            'created_at' => $r->created_at?->toIso8601String(),
            'started_at' => $r->started_at?->toIso8601String(),
            'finished_at' => $r->finished_at?->toIso8601String(),
            'error' => $r->error,
        ];
    }
}
