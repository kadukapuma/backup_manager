<?php

use App\Enums\DatabaseState;
use App\Enums\NewDatabasePolicy;
use App\Enums\NotificationEvent;
use App\Enums\RuleType;
use App\Enums\StateSource;
use App\Enums\TestStatus;
use App\Jobs\DiscoverDatabasesJob;
use App\Models\Database;
use App\Models\NotificationChannel;
use App\Models\SelectionRule;
use App\Models\ServerConnection;
use App\Notifications\BackupAlert;
use App\Services\Database\DatabaseServerClient;
use App\Services\Discovery\DatabaseDiscoverer;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Fakes\FakeDatabaseServerClient;

beforeEach(function () {
    Notification::fake();
    NotificationChannel::factory()->create(['config' => ['recipients' => 'ops@example.com']]);
});

function fakeServer(array $tables): FakeDatabaseServerClient
{
    $fake = FakeDatabaseServerClient::with($tables);
    app()->instance(DatabaseServerClient::class, $fake);

    return $fake;
}

test('new databases follow rules first and the policy otherwise', function () {
    $conn = ServerConnection::factory()->create(['new_database_policy' => NewDatabasePolicy::Pending]);
    SelectionRule::factory()->create(['connection_id' => $conn->id, 'type' => RuleType::Include, 'pattern' => 'kreethya_*', 'priority' => 10]);
    SelectionRule::factory()->create(['connection_id' => $conn->id, 'type' => RuleType::Exclude, 'pattern' => '*_test', 'priority' => 5]);
    fakeServer(['kreethya_shop' => 10, 'kreethya_test' => 2, 'fixflow_acme' => 30]);

    (new DiscoverDatabasesJob($conn->id))->handle(app(DatabaseDiscoverer::class));

    $states = Database::query()->pluck('state', 'name')->map(fn ($s) => $s->value)->all();
    expect($states)->toBe([
        'kreethya_shop' => 'included',
        'kreethya_test' => 'excluded',
        'fixflow_acme' => 'pending',
    ]);
    expect(Database::query()->where('name', 'fixflow_acme')->value('table_count'))->toBe(30);

    Notification::assertSentTo(new AnonymousNotifiable, BackupAlert::class,
        fn (BackupAlert $n) => $n->event === NotificationEvent::DatabasePending && str_contains(implode(' ', $n->lines), 'fixflow_acme'));
});

test('auto include policy includes unmatched databases', function () {
    $conn = ServerConnection::factory()->create(['new_database_policy' => NewDatabasePolicy::AutoInclude]);
    fakeServer(['fixflow_new' => 5]);

    app(DatabaseDiscoverer::class)->discover($conn);

    $db = Database::query()->firstOrFail();
    expect($db->state)->toBe(DatabaseState::Included)->and($db->state_source)->toBe(StateSource::Policy);
    Notification::assertNothingSent();
});

test('disappeared databases are marked missing and never deleted', function () {
    $conn = ServerConnection::factory()->create();
    $gone = Database::factory()->create(['connection_id' => $conn->id, 'name' => 'old_client']);
    $kept = Database::factory()->create(['connection_id' => $conn->id, 'name' => 'live_client', 'state' => DatabaseState::Excluded]);
    fakeServer(['live_client' => 3]);

    app(DatabaseDiscoverer::class)->discover($conn);

    expect($gone->fresh()->missing_since)->not->toBeNull()
        ->and($kept->fresh()->missing_since)->toBeNull()
        ->and($kept->fresh()->state)->toBe(DatabaseState::Excluded);

    Notification::assertSentTo(new AnonymousNotifiable, BackupAlert::class,
        fn (BackupAlert $n) => $n->event === NotificationEvent::DatabaseMissing);
});

test('a reappearing database clears missing_since and keeps its manual state', function () {
    $conn = ServerConnection::factory()->create();
    $db = Database::factory()->create(['connection_id' => $conn->id, 'name' => 'back_again', 'missing_since' => now()->subDay(), 'state' => DatabaseState::Excluded]);
    fakeServer(['back_again' => 1]);

    app(DatabaseDiscoverer::class)->discover($conn);

    expect($db->fresh()->missing_since)->toBeNull()->and($db->fresh()->state)->toBe(DatabaseState::Excluded);
});

test('discovery failure is recorded on the connection without leaking the password', function () {
    $conn = ServerConnection::factory()->create(['password' => 'Sup3rSecret!']);
    fakeServer([])->fail = true;

    (new DiscoverDatabasesJob($conn->id))->handle(app(DatabaseDiscoverer::class));

    $conn->refresh();
    expect($conn->last_test_status)->toBe(TestStatus::Failed)
        ->and($conn->last_test_message)->toContain('Access denied')
        ->and($conn->last_test_message)->not->toContain('Sup3rSecret!');
});
