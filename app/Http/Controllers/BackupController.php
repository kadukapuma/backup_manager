<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Backups\StartBackupRun;
use App\Enums\AuditAction;
use App\Enums\CopyStatus;
use App\Enums\DatabaseState;
use App\Enums\DestinationType;
use App\Enums\RunTrigger;
use App\Exceptions\BackupException;
use App\Jobs\DeleteBackupFileJob;
use App\Models\BackupFile;
use App\Models\BackupPlan;
use App\Models\Database;
use App\Models\Destination;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * "Back up now" for selected databases. Uses the destinations of the
     * active plans that cover each connection, or every active destination.
     */
    public function manual(Request $request, StartBackupRun $start): RedirectResponse
    {
        $this->authorize('backupNow', new Database);

        $data = $request->validate([
            'database_ids' => ['required', 'array', 'min:1', 'max:500'],
            'database_ids.*' => ['integer'],
        ]);

        $databases = Database::query()->with('serverConnection')
            ->whereIn('id', $data['database_ids'])
            ->where('state', DatabaseState::Included->value)
            ->whereNull('missing_since')
            ->get();

        if ($databases->isEmpty()) {
            return back()->with('error', 'Only included databases can be backed up. Approve or include them first.');
        }

        /** @var User $user */
        $user = $request->user();
        $runs = [];

        try {
            foreach ($databases->groupBy('connection_id') as $group) {
                /** @var Database $first */
                $first = $group->first();
                $run = $start->handle(
                    $first->serverConnection,
                    new Collection($group->all()),
                    $this->destinationsFor($first->connection_id),
                    RunTrigger::Manual,
                    null,
                    $user,
                );
                $runs[] = $run->id;
            }
        } catch (BackupException $e) {
            return back()->with('error', $e->getMessage());
        }

        $skipped = count($data['database_ids']) - $databases->count();
        $message = 'Backup queued for '.$databases->count().' database(s).'.($skipped > 0 ? " {$skipped} not included and skipped." : '');

        return count($runs) === 1
            ? redirect()->route('runs.show', $runs[0])->with('success', $message)
            : redirect()->route('runs.index')->with('success', $message);
    }

    public function runPlan(Request $request, BackupPlan $plan, StartBackupRun $start): RedirectResponse
    {
        $this->authorize('run', $plan);

        /** @var User $user */
        $user = $request->user();

        try {
            $run = $start->handle($plan->serverConnection, $plan->targetDatabases(), $plan->destinations, RunTrigger::Manual, $plan, $user);
        } catch (BackupException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('runs.show', $run->id)->with('success', "Plan {$plan->name} started.");
    }

    /**
     * Streams the encrypted file from a local-disk destination. Remote copies
     * must be fetched with rclone on the server (see ROADMAP).
     */
    public function download(BackupFile $file): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('download', $file);

        $copy = $file->copies()->with('destination')
            ->whereIn('status', [CopyStatus::Verified->value, CopyStatus::Uploaded->value])
            ->get()
            ->first(fn ($c) => $c->destination->type === DestinationType::Local && is_readable($c->remote_path));

        if ($copy === null || $file->filename === null) {
            return back()->with('error', 'No readable local copy of this backup exists on this server.');
        }

        $this->audit->log(AuditAction::BackupDownloaded, $file, [
            'filename' => $file->filename,
            'destination' => $copy->destination->name,
        ]);

        return response()->download($copy->remote_path, $file->filename, ['Content-Type' => 'application/octet-stream']);
    }

    public function destroy(BackupFile $file): RedirectResponse
    {
        $this->authorize('delete', $file);

        $this->audit->log(AuditAction::BackupDeleted, $file, [
            'filename' => $file->filename,
            'database' => $file->database->name,
        ]);
        DeleteBackupFileJob::dispatch($file->id);

        return back()->with('success', 'Deletion of all copies has been queued.');
    }

    /**
     * @return Collection<int, Destination>
     */
    private function destinationsFor(int $connectionId): Collection
    {
        $fromPlans = Destination::query()
            ->where('is_active', true)
            ->whereHas('backupPlans', fn ($q) => $q->where('connection_id', $connectionId)->where('is_active', true))
            ->get();

        return $fromPlans->isNotEmpty() ? $fromPlans : Destination::query()->where('is_active', true)->get();
    }
}
