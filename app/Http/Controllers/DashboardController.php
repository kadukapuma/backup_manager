<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CopyStatus;
use App\Enums\DatabaseState;
use App\Enums\RunStatus;
use App\Enums\TestStatus;
use App\Models\BackupCopy;
use App\Models\BackupFile;
use App\Models\BackupRun;
use App\Models\Database;
use App\Models\Destination;
use App\Models\ServerConnection;
use App\Services\Monitoring\StaleBackupDetector;
use App\Services\Settings\SettingsStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(StaleBackupDetector $detector, SettingsStore $settings): Response
    {
        $stale = $detector->stale();
        $since = now()->subDay();

        $failedRuns24h = BackupRun::query()->where('created_at', '>=', $since)
            ->whereIn('status', [RunStatus::Failed->value, RunStatus::Partial->value])->count();
        $failedJobs = DB::table('failed_jobs')->orderByDesc('id')->limit(5)->get(['id', 'queue', 'payload', 'exception', 'failed_at']);
        $failedJobsCount = DB::table('failed_jobs')->count();
        $failingDestinations = Destination::query()->where('is_active', true)->where('last_test_status', TestStatus::Failed->value)->pluck('name');
        $failingConnections = ServerConnection::query()->where('is_active', true)->where('last_test_status', TestStatus::Failed->value)->pluck('name');
        $pending = Database::query()->with('serverConnection:id,name')->where('state', DatabaseState::Pending->value)
            ->whereNull('missing_since')->orderBy('first_seen_at')->get();
        $hasKey = $settings->agePublicKey() !== null;

        $problems = [];
        if (! $hasKey) {
            $problems[] = 'No age public key is configured, so backups cannot run.';
        }
        if ($stale->isNotEmpty()) {
            $problems[] = $stale->count().' database(s) have no successful backup in the last '.$settings->staleAfterHours().' hours.';
        }
        if ($failedRuns24h > 0) {
            $problems[] = $failedRuns24h.' run(s) failed or partially failed in the last 24 hours.';
        }
        if ($failedJobsCount > 0) {
            $problems[] = $failedJobsCount.' queue job(s) failed.';
        }
        foreach ($failingDestinations as $name) {
            $problems[] = "Destination {$name} failed its last test.";
        }
        foreach ($failingConnections as $name) {
            $problems[] = "Connection {$name} is unreachable.";
        }

        $warnings = [];
        if ($pending->isNotEmpty()) {
            $warnings[] = $pending->count().' new database(s) are waiting for approval and are not backed up.';
        }

        $usage = BackupCopy::query()
            ->join('backup_files', 'backup_files.id', '=', 'backup_copies.backup_file_id')
            ->whereIn('backup_copies.status', [CopyStatus::Uploaded->value, CopyStatus::Verified->value])
            ->groupBy('backup_copies.destination_id')
            ->selectRaw('backup_copies.destination_id, COUNT(*) as copies, COALESCE(SUM(backup_files.size_bytes), 0) as bytes')
            ->get()->keyBy('destination_id');

        return Inertia::render('dashboard', [
            'health' => [
                'ok' => $problems === [],
                'problems' => $problems,
                'warnings' => $warnings,
            ],
            'stats' => [
                'included' => Database::query()->included()->count(),
                'backups_24h' => BackupFile::query()->where('created_at', '>=', $since)->where('status', 'success')->count(),
                'bytes_24h' => (int) BackupFile::query()->where('created_at', '>=', $since)->where('status', 'success')->sum('size_bytes'),
                'running' => BackupRun::query()->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])->count(),
                'stale_after_hours' => $settings->staleAfterHours(),
            ],
            'stale' => $stale->take(20)->map(fn (Database $d): array => [
                'id' => $d->id,
                'name' => $d->name,
                'connection' => $d->serverConnection->name,
                'last_success_at' => $d->last_success_at?->toIso8601String(),
            ])->values(),
            'staleCount' => $stale->count(),
            'runs' => BackupRun::query()->with(['plan:id,name', 'serverConnection:id,name', 'triggeredBy:id,name'])->withCount('files')
                ->orderByDesc('id')->limit(10)->get()->map(fn (BackupRun $r): array => BackupRunController::presentRun($r)),
            'destinations' => Destination::query()->orderBy('name')->get()->map(fn (Destination $d): array => [
                'id' => $d->id,
                'name' => $d->name,
                'type' => $d->type->value,
                'is_active' => $d->is_active,
                'last_test_status' => $d->last_test_status?->value,
                'stored_bytes' => (int) ($usage[$d->id]->bytes ?? 0),
                'copies' => (int) ($usage[$d->id]->copies ?? 0),
                'free_space_bytes' => $d->free_space_bytes,
            ]),
            'pending' => $pending->take(20)->map(fn (Database $d): array => [
                'id' => $d->id,
                'name' => $d->name,
                'connection' => $d->serverConnection->name,
                'size_bytes' => $d->size_bytes,
                'first_seen_at' => $d->first_seen_at?->toIso8601String(),
            ])->values(),
            'pendingCount' => $pending->count(),
            'failedJobs' => $failedJobs->map(fn ($j): array => [
                'id' => $j->id,
                'queue' => $j->queue,
                'job' => class_basename((string) (json_decode((string) $j->payload, true)['displayName'] ?? 'Job')),
                'error' => Str::limit(strtok((string) $j->exception, "\n") ?: '', 200),
                'failed_at' => $j->failed_at,
            ]),
            'failedJobsCount' => $failedJobsCount,
        ]);
    }
}
