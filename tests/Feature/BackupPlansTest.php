<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\BackupPlan;
use App\Models\Database;
use App\Models\Destination;
use App\Models\ServerConnection;

function planPayload(ServerConnection $conn, array $destinationIds, array $overrides = []): array
{
    return [
        'name' => 'Nightly',
        'connection_id' => $conn->id,
        'cron_expression' => '0  2 * * *',
        'timezone' => 'Asia/Colombo',
        'retention' => ['keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 6],
        'all_included_databases' => true,
        'database_ids' => [],
        'destination_ids' => $destinationIds,
        'is_active' => true,
        ...$overrides,
    ];
}

test('admin creates a plan; next run is calculated and encryption is forced on', function () {
    $conn = ServerConnection::factory()->create();
    $dest = Destination::factory()->create();
    actingAsRole(Role::Admin);

    $this->post('/plans', planPayload($conn, [$dest->id], ['encrypt' => false]))->assertSessionHasNoErrors();

    $plan = BackupPlan::query()->firstOrFail();
    expect($plan->cron_expression)->toBe('0 2 * * *')
        ->and($plan->encrypt)->toBeTrue()
        ->and($plan->next_run_at)->not->toBeNull()
        ->and($plan->next_run_at->format('H:i'))->toBe('02:00')
        ->and($plan->destinations()->pluck('destinations.id')->all())->toBe([$dest->id]);
    expect(AuditLog::query()->where('action', AuditAction::PlanCreated->value)->exists())->toBeTrue();
});

test('plan validation rejects bad cron, missing destinations and foreign databases', function () {
    $conn = ServerConnection::factory()->create();
    $otherDb = Database::factory()->create();
    actingAsRole(Role::Admin);

    $this->post('/plans', planPayload($conn, [], ['cron_expression' => 'every day']))
        ->assertSessionHasErrors(['cron_expression', 'destination_ids']);

    $dest = Destination::factory()->create();
    $this->post('/plans', planPayload($conn, [$dest->id], ['all_included_databases' => false, 'database_ids' => [$otherDb->id]]))
        ->assertSessionHasErrors('database_ids');
});

test('target databases exclude pending, excluded and missing ones', function () {
    $conn = ServerConnection::factory()->create();
    $included = Database::factory()->create(['connection_id' => $conn->id, 'name' => 'a_live']);
    Database::factory()->pending()->create(['connection_id' => $conn->id]);
    Database::factory()->excluded()->create(['connection_id' => $conn->id]);
    Database::factory()->create(['connection_id' => $conn->id, 'missing_since' => now()]);
    $plan = BackupPlan::factory()->create(['connection_id' => $conn->id]);

    expect($plan->targetDatabases()->pluck('id')->all())->toBe([$included->id]);

    $excluded = Database::factory()->excluded()->create(['connection_id' => $conn->id]);
    $plan->update(['all_included_databases' => false]);
    $plan->databases()->sync([$included->id, $excluded->id]);

    expect($plan->targetDatabases()->pluck('id')->all())->toBe([$included->id]);
});

test('cron preview returns next five runs', function () {
    actingAsRole(Role::Viewer);

    $this->getJson('/plans/cron-preview?expression=0%20*/6%20*%20*%20*&timezone=Asia/Colombo')
        ->assertOk()->assertJsonPath('valid', true)->assertJsonCount(5, 'runs');

    $this->getJson('/plans/cron-preview?expression=nope&timezone=Asia/Colombo')->assertJsonPath('valid', false);
});

test('only admins manage plans', function (Role $role) {
    $plan = BackupPlan::factory()->create();
    $dest = Destination::factory()->create();
    actingAsRole($role);

    $this->get('/plans')->assertOk();
    $this->post('/plans', planPayload($plan->serverConnection, [$dest->id], ['name' => 'x']))->assertForbidden();
    $this->delete("/plans/{$plan->id}")->assertForbidden();
})->with([Role::Operator, Role::Viewer]);
