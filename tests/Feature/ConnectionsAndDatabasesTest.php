<?php

use App\Enums\AuditAction;
use App\Enums\DatabaseState;
use App\Enums\Role;
use App\Enums\StateSource;
use App\Jobs\DiscoverDatabasesJob;
use App\Models\AuditLog;
use App\Models\BackupFile;
use App\Models\Database;
use App\Models\SelectionRule;
use App\Models\ServerConnection;
use App\Services\Database\DatabaseServerClient;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeDatabaseServerClient;

function connectionPayload(array $overrides = []): array
{
    return [
        'name' => 'vps-main',
        'driver' => 'mariadb',
        'host' => '127.0.0.1',
        'port' => 3306,
        'username' => 'backup',
        'password' => 'Db-Pass-123',
        'socket' => '',
        'new_database_policy' => 'pending',
        'is_active' => true,
        ...$overrides,
    ];
}

test('admin creates a connection with an encrypted password and discovery is queued', function () {
    Queue::fake();
    actingAsRole(Role::Admin);

    $this->post('/connections', connectionPayload())->assertSessionHasNoErrors();

    $conn = ServerConnection::query()->firstOrFail();
    expect($conn->password)->toBe('Db-Pass-123')
        ->and(DB::table('connections')->value('password'))->not->toContain('Db-Pass-123');
    Queue::assertPushed(DiscoverDatabasesJob::class);
    expect(AuditLog::query()->where('action', AuditAction::ConnectionCreated->value)->exists())->toBeTrue();
});

test('the password is never sent to the browser', function () {
    ServerConnection::factory()->create(['password' => 'Never-Show-Me']);
    actingAsRole(Role::Admin);

    $response = $this->get('/connections')->assertOk();

    expect($response->getContent())->not->toContain('Never-Show-Me');
    $response->assertInertia(fn ($page) => $page->where('connections.0.password_set', true));
});

test('blank password on update keeps the stored password', function () {
    $conn = ServerConnection::factory()->create(['name' => 'vps-main', 'password' => 'Keep-Me']);
    actingAsRole(Role::Admin);

    $this->put("/connections/{$conn->id}", connectionPayload(['password' => '', 'port' => 3307]))->assertSessionHasNoErrors();

    expect($conn->fresh()->password)->toBe('Keep-Me')->and($conn->fresh()->port)->toBe(3307);
    expect(AuditLog::query()->where('action', AuditAction::ConnectionCredentialsChanged->value)->exists())->toBeTrue();
});

test('operators and viewers cannot manage connections', function (Role $role) {
    $conn = ServerConnection::factory()->create();
    actingAsRole($role);

    $this->post('/connections', connectionPayload())->assertForbidden();
    $this->put("/connections/{$conn->id}", connectionPayload())->assertForbidden();
    $this->delete("/connections/{$conn->id}")->assertForbidden();
    $this->post("/connections/{$conn->id}/test")->assertForbidden();
})->with([Role::Operator, Role::Viewer]);

test('connection test records status', function () {
    app()->instance(DatabaseServerClient::class, FakeDatabaseServerClient::with([]));
    $conn = ServerConnection::factory()->create();
    actingAsRole(Role::Admin);

    $this->post("/connections/{$conn->id}/test")->assertSessionHas('success');

    expect($conn->fresh()->last_test_status?->value)->toBe('ok');
});

test('connections with backup history cannot be deleted', function () {
    $conn = ServerConnection::factory()->create();
    $db = Database::factory()->create(['connection_id' => $conn->id]);
    BackupFile::factory()->create(['database_id' => $db->id]);
    actingAsRole(Role::Admin);

    $this->delete("/connections/{$conn->id}")->assertSessionHas('error');

    expect(ServerConnection::query()->count())->toBe(1);
});

test('operators can approve and exclude databases; viewers cannot', function () {
    $pending = Database::factory()->pending()->create();
    actingAsRole(Role::Operator);

    $this->post('/databases/state', ['ids' => [$pending->id], 'state' => 'included'])->assertSessionHas('success');
    expect($pending->fresh()->state)->toBe(DatabaseState::Included)
        ->and($pending->fresh()->state_source)->toBe(StateSource::Manual);

    actingAsRole(Role::Viewer);
    $this->post('/databases/state', ['ids' => [$pending->id], 'state' => 'excluded'])->assertForbidden();
});

test('switching a database back to automatic re-applies rules', function () {
    $conn = ServerConnection::factory()->create();
    SelectionRule::factory()->create(['connection_id' => $conn->id, 'type' => 'exclude', 'pattern' => '*_test']);
    $db = Database::factory()->create(['connection_id' => $conn->id, 'name' => 'shop_test', 'state' => DatabaseState::Included]);
    actingAsRole(Role::Operator);

    $this->post('/databases/state', ['ids' => [$db->id], 'state' => 'automatic']);

    expect($db->fresh()->state)->toBe(DatabaseState::Excluded)->and($db->fresh()->state_source)->toBe(StateSource::Rule);
});

test('databases page filters by state and search', function () {
    Database::factory()->create(['name' => 'fixflow_one']);
    Database::factory()->pending()->create(['name' => 'fixflow_two']);
    Database::factory()->create(['name' => 'other']);
    actingAsRole(Role::Viewer);

    $this->get('/databases?state=pending')->assertInertia(fn ($page) => $page->has('databases.data', 1)->where('databases.data.0.name', 'fixflow_two'));
    $this->get('/databases?search=fixflow')->assertInertia(fn ($page) => $page->has('databases.data', 2));
});

test('rule preview returns matching names and rules can be applied to existing databases', function () {
    $conn = ServerConnection::factory()->create();
    Database::factory()->create(['connection_id' => $conn->id, 'name' => 'kreethya_a', 'state' => DatabaseState::Pending, 'state_source' => StateSource::Policy]);
    Database::factory()->create(['connection_id' => $conn->id, 'name' => 'kreethya_b', 'state' => DatabaseState::Excluded, 'state_source' => StateSource::Manual]);
    Database::factory()->create(['connection_id' => $conn->id, 'name' => 'other']);
    actingAsRole(Role::Admin);

    $this->getJson("/rules/preview?connection_id={$conn->id}&pattern=kreethya_*")
        ->assertOk()->assertJson(['total' => 2, 'matches' => ['kreethya_a', 'kreethya_b']]);

    $this->post('/rules', ['connection_id' => $conn->id, 'type' => 'include', 'pattern' => 'kreethya_*', 'priority' => 10, 'is_active' => true])
        ->assertSessionHasNoErrors();
    $this->post("/connections/{$conn->id}/apply-rules")->assertSessionHas('success');

    expect(Database::query()->where('name', 'kreethya_a')->first()->state)->toBe(DatabaseState::Included)
        ->and(Database::query()->where('name', 'kreethya_b')->first()->state)->toBe(DatabaseState::Excluded);
});

test('invalid rule patterns are rejected', function () {
    $conn = ServerConnection::factory()->create();
    actingAsRole(Role::Admin);

    $this->post('/rules', ['connection_id' => $conn->id, 'type' => 'include', 'pattern' => 'shop; DROP', 'priority' => 1, 'is_active' => true])
        ->assertSessionHasErrors('pattern');
});

test('rules page renders for viewers', function () {
    SelectionRule::factory()->create();
    actingAsRole(Role::Viewer);

    $this->get('/rules')->assertOk()->assertInertia(fn ($page) => $page->component('rules/index')->has('groups', 1));
});
