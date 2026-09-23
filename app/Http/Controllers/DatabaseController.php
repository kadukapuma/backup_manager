<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Databases\ChangeDatabaseState;
use App\Enums\DatabaseState;
use App\Models\Database;
use App\Models\ServerConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Database::class);

        $filters = $request->validate([
            'connection' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:64'],
            'state' => ['nullable', Rule::in(['included', 'excluded', 'pending', 'missing'])],
        ]);

        $connections = ServerConnection::query()->orderBy('name')->get(['id', 'name']);
        $connectionId = isset($filters['connection']) ? (int) $filters['connection'] : null;

        $query = Database::query()
            ->with(['serverConnection:id,name', 'latestSuccessfulBackup'])
            ->when($connectionId, fn ($q, $id) => $q->where('connection_id', $id))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', '%'.$s.'%'))
            ->when($filters['state'] ?? null, function ($q, string $state) {
                if ($state === 'missing') {
                    $q->whereNotNull('missing_since');
                } else {
                    $q->where('state', $state)->whereNull('missing_since');
                }
            })
            ->orderBy('name');

        $databases = $query->paginate(100)->withQueryString()->through(fn (Database $db): array => self::present($db));

        $counts = Database::query()
            ->when($connectionId, fn ($q, $id) => $q->where('connection_id', $id))
            ->selectRaw('state, COUNT(*) as aggregate')
            ->whereNull('missing_since')
            ->groupBy('state')
            ->pluck('aggregate', 'state');

        return Inertia::render('databases/index', [
            'databases' => $databases,
            'connections' => $connections->map(fn (ServerConnection $c): array => ['value' => (string) $c->id, 'label' => $c->name]),
            'filters' => [
                'connection' => $connectionId !== null ? (string) $connectionId : '',
                'search' => $filters['search'] ?? '',
                'state' => $filters['state'] ?? '',
            ],
            'counts' => [
                'included' => (int) ($counts['included'] ?? 0),
                'excluded' => (int) ($counts['excluded'] ?? 0),
                'pending' => (int) ($counts['pending'] ?? 0),
                'missing' => Database::query()->when($connectionId, fn ($q, $id) => $q->where('connection_id', $id))->whereNotNull('missing_since')->count(),
            ],
        ]);
    }

    public function updateState(Request $request, ChangeDatabaseState $action): RedirectResponse
    {
        $this->authorize('changeState', new Database);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['integer'],
            'state' => ['required', Rule::in(['included', 'excluded', 'automatic'])],
        ]);

        $state = $data['state'] === 'automatic' ? null : DatabaseState::from($data['state']);
        $databases = Database::query()->with('serverConnection')->whereIn('id', $data['ids'])->get();

        $changed = $action->handle($databases, $state);

        return back()->with('success', $changed === 1 ? '1 database updated.' : "{$changed} databases updated.");
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Database $db): array
    {
        return [
            'id' => $db->id,
            'name' => $db->name,
            'connection_id' => $db->connection_id,
            'connection_name' => $db->serverConnection->name,
            'state' => $db->state->value,
            'state_source' => $db->state_source->value,
            'size_bytes' => $db->size_bytes,
            'table_count' => $db->table_count,
            'first_seen_at' => $db->first_seen_at?->toIso8601String(),
            'last_seen_at' => $db->last_seen_at?->toIso8601String(),
            'missing_since' => $db->missing_since?->toIso8601String(),
            'last_backup_at' => $db->latestSuccessfulBackup?->created_at->toIso8601String(),
            'last_backup_size' => $db->latestSuccessfulBackup?->size_bytes,
        ];
    }
}
