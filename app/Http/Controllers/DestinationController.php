<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Destinations\SaveDestination;
use App\Enums\AuditAction;
use App\Enums\CopyStatus;
use App\Enums\DestinationType;
use App\Enums\TestStatus;
use App\Http\Requests\Destinations\SaveDestinationRequest;
use App\Jobs\TestDestinationJob;
use App\Models\BackupCopy;
use App\Models\Destination;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DestinationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Destination::class);

        $usage = BackupCopy::query()
            ->join('backup_files', 'backup_files.id', '=', 'backup_copies.backup_file_id')
            ->whereIn('backup_copies.status', [CopyStatus::Uploaded->value, CopyStatus::Verified->value])
            ->groupBy('backup_copies.destination_id')
            ->selectRaw('backup_copies.destination_id, COUNT(*) as copies, COALESCE(SUM(backup_files.size_bytes), 0) as bytes')
            ->get()
            ->keyBy('destination_id');

        $destinations = Destination::query()->orderBy('name')->get()->map(fn (Destination $d): array => [
            'id' => $d->id,
            'name' => $d->name,
            'type' => $d->type->value,
            'type_label' => $d->type->label(),
            'base_path' => $d->base_path,
            'config' => $d->publicConfig(),
            'is_active' => $d->is_active,
            'last_tested_at' => $d->last_tested_at?->toIso8601String(),
            'last_test_status' => $d->last_test_status?->value,
            'last_test_message' => $d->last_test_message,
            'free_space_bytes' => $d->free_space_bytes,
            'copies' => (int) ($usage[$d->id]->copies ?? 0),
            'stored_bytes' => (int) ($usage[$d->id]->bytes ?? 0),
        ]);

        return Inertia::render('destinations/index', [
            'destinations' => $destinations,
            'types' => array_values(array_filter(DestinationType::options(), fn (array $o): bool => in_array($o['value'], DestinationType::availableValues(), true))),
            's3Providers' => SaveDestinationRequest::S3_PROVIDERS,
        ]);
    }

    public function store(SaveDestinationRequest $request, SaveDestination $action): RedirectResponse
    {
        $destination = $action->handle($request->validated());
        $this->queueTest($destination);

        return back()->with('success', 'Destination saved. A write/read/delete test has been queued.');
    }

    public function update(SaveDestinationRequest $request, Destination $destination, SaveDestination $action): RedirectResponse
    {
        $action->handle($request->validated(), $destination);

        return back()->with('success', 'Destination updated.');
    }

    public function destroy(Destination $destination): RedirectResponse
    {
        $this->authorize('delete', $destination);

        $live = $destination->copies()->where('status', '!=', CopyStatus::Deleted->value)->exists();
        if ($live) {
            return back()->with('error', 'This destination still holds backup copies. Deactivate it instead, or let retention remove the copies first.');
        }

        $this->audit->log(AuditAction::DestinationDeleted, $destination, ['name' => $destination->name]);
        $destination->copies()->delete();
        $destination->delete();

        return back()->with('success', 'Destination deleted.');
    }

    public function test(Destination $destination): RedirectResponse
    {
        $this->authorize('test', $destination);

        $this->queueTest($destination);
        $this->audit->log(AuditAction::DestinationTested, $destination, ['name' => $destination->name]);

        return back()->with('success', 'Test queued. The result will appear in a few seconds.');
    }

    private function queueTest(Destination $destination): void
    {
        $destination->forceFill(['last_test_status' => TestStatus::Running, 'last_test_message' => 'Test queued…'])->save();
        TestDestinationJob::dispatch($destination->id);
    }
}
