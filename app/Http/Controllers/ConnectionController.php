<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Connections\SaveConnection;
use App\Actions\Connections\TestConnection;
use App\Enums\AuditAction;
use App\Enums\ConnectionDriver;
use App\Enums\DatabaseState;
use App\Enums\NewDatabasePolicy;
use App\Http\Requests\Connections\SaveConnectionRequest;
use App\Jobs\DiscoverDatabasesJob;
use App\Models\ServerConnection;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('viewAny', ServerConnection::class);

        $connections = ServerConnection::query()
            ->withCount([
                'databases',
                'databases as included_count' => fn ($q) => $q->where('state', DatabaseState::Included->value)->whereNull('missing_since'),
                'databases as pending_count' => fn ($q) => $q->where('state', DatabaseState::Pending->value)->whereNull('missing_since'),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (ServerConnection $c): array => self::present($c));

        return Inertia::render('connections/index', [
            'connections' => $connections,
            'drivers' => array_values(array_filter(ConnectionDriver::options(), fn (array $o): bool => in_array($o['value'], ConnectionDriver::supportedValues(), true))),
            'policies' => NewDatabasePolicy::options(),
        ]);
    }

    public function store(SaveConnectionRequest $request, SaveConnection $action): RedirectResponse
    {
        $connection = $action->handle($request->validated());
        DiscoverDatabasesJob::dispatch($connection->id);

        return back()->with('success', 'Connection saved. Discovery has been queued.');
    }

    public function update(SaveConnectionRequest $request, ServerConnection $connection, SaveConnection $action): RedirectResponse
    {
        $action->handle($request->validated(), $connection);

        return back()->with('success', 'Connection updated.');
    }

    public function destroy(ServerConnection $connection): RedirectResponse
    {
        $this->authorize('delete', $connection);

        if ($connection->hasBackupHistory()) {
            return back()->with('error', 'This connection has backup history and cannot be deleted. Deactivate it instead.');
        }

        $this->audit->log(AuditAction::ConnectionDeleted, $connection, ['name' => $connection->name]);
        $connection->delete();

        return back()->with('success', 'Connection deleted.');
    }

    public function test(ServerConnection $connection, TestConnection $action): RedirectResponse
    {
        $this->authorize('test', $connection);

        return $action->handle($connection)
            ? back()->with('success', (string) $connection->last_test_message)
            : back()->with('error', 'Connection failed: '.$connection->last_test_message);
    }

    public function discover(ServerConnection $connection): RedirectResponse
    {
        $this->authorize('discover', $connection);

        DiscoverDatabasesJob::dispatch($connection->id);
        $this->audit->log(AuditAction::DiscoveryRequested, $connection, ['name' => $connection->name]);

        return back()->with('success', 'Discovery queued for '.$connection->name.'.');
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(ServerConnection $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'driver' => $c->driver->value,
            'host' => $c->host,
            'port' => $c->port,
            'username' => $c->username,
            'password_set' => $c->password !== null && $c->password !== '',
            'socket' => $c->socket,
            'new_database_policy' => $c->new_database_policy->value,
            'is_active' => $c->is_active,
            'last_tested_at' => $c->last_tested_at?->toIso8601String(),
            'last_test_status' => $c->last_test_status?->value,
            'last_test_message' => $c->last_test_message,
            'last_discovered_at' => $c->last_discovered_at?->toIso8601String(),
            'databases_count' => (int) ($c->databases_count ?? 0),
            'included_count' => (int) ($c->included_count ?? 0),
            'pending_count' => (int) ($c->pending_count ?? 0),
        ];
    }
}
