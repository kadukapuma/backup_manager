<?php

use App\Enums\AuditAction;
use App\Enums\BackupFileStatus;
use App\Enums\CopyStatus;
use App\Enums\NotificationEvent;
use App\Enums\RestoreMode;
use App\Enums\RestoreStatus;
use App\Enums\Role;
use App\Enums\RunTrigger;
use App\Jobs\RestoreDatabaseJob;
use App\Models\AuditLog;
use App\Models\BackupCopy;
use App\Models\BackupFile;
use App\Models\BackupRun;
use App\Models\Database;
use App\Models\Destination;
use App\Models\NotificationChannel;
use App\Models\RestoreJob;
use App\Models\ServerConnection;
use App\Notifications\BackupAlert;
use App\Services\Database\DatabaseServerClient;
use App\Services\Settings\SettingsStore;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeBackupTools;
use Tests\Fakes\FakeDatabaseServerClient;

const IDENTITY = 'AGE-SECRET-KEY-1QQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQ';

beforeEach(function () {
    $root = sys_get_temp_dir().'/bm-restore-'.uniqid();
    config([
        'backup-manager.staging_path' => $root.'/staging',
        'backup-manager.tmp_path' => $root.'/tmp',
        'backup-manager.lock_wait_attempts' => 0,
        'backup-manager.age_identity_file' => null,
    ]);
    $this->root = $root;
    app(SettingsStore::class)->set(SettingsStore::AGE_PUBLIC_KEY, 'age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p');
    Notification::fake();
    NotificationChannel::factory()->create();

    $this->tools = FakeBackupTools::install();
    $this->server = FakeDatabaseServerClient::with(['shop' => 12]);
    app()->instance(DatabaseServerClient::class, $this->server);

    $this->connection = ServerConnection::factory()->create(['name' => 'vps', 'password' => 'Db-Secret']);
    $this->database = Database::factory()->create(['connection_id' => $this->connection->id, 'name' => 'shop', 'table_count' => 12]);
    $this->local = Destination::factory()->create(['name' => 'local', 'base_path' => '/var/backups/db']);
    $this->offsite = Destination::factory()->sftp()->create(['name' => 'offsite', 'base_path' => 'backups']);

    // An existing backup with two stored copies.
    $content = 'AGE-ENCRYPTED:ORIGINAL';
    $run = BackupRun::factory()->create(['trigger' => RunTrigger::Scheduled, 'connection_id' => $this->connection->id]);
    $this->file = BackupFile::factory()->create([
        'backup_run_id' => $run->id,
        'database_id' => $this->database->id,
        'filename' => 'shop__20260920-020000__scheduled.sql.zst.age',
        'sha256' => hash('sha256', $content),
        'manifest' => ['table_count' => 12, 'default_charset' => 'utf8mb4', 'default_collation' => 'utf8mb4_general_ci'],
    ]);
    foreach ([$this->local, $this->offsite] as $dest) {
        $path = $dest->pathFor('vps/shop/'.$this->file->filename);
        $copy = BackupCopy::factory()->create(['backup_file_id' => $this->file->id, 'destination_id' => $dest->id, 'remote_path' => $path]);
        $spec = 'BMDEST:'.$path;
        $this->tools->remote[$spec] = ['size' => strlen($content), 'sha256' => hash('sha256', $content), 'content' => $content];
        $this->copies[$dest->name] = $copy;
    }
});

function restorePayload(array $overrides = []): array
{
    return [
        'backup_file_id' => test()->file->id,
        'source_copy_id' => test()->copies['offsite']->id,
        'target_connection_id' => test()->connection->id,
        'target_database' => 'shop_restore_20260923',
        'mode' => 'new_copy',
        'confirmation' => 'shop_restore_20260923',
        'age_identity' => "# created: 2026-01-01\n# public key: age1xyz\n".IDENTITY."\n",
        ...$overrides,
    ];
}

test('a new-copy restore downloads, verifies, decrypts, imports and post-checks', function () {
    $this->server->tableCounts['shop_restore_20260923'] = 12;
    actingAsRole(Role::Operator);

    $this->post('/restores', restorePayload())->assertRedirect();

    $restore = RestoreJob::query()->firstOrFail();
    expect($restore->status)->toBe(RestoreStatus::Success)
        ->and($restore->mode)->toBe(RestoreMode::NewCopy)
        ->and($restore->safety_backup_file_id)->toBeNull()
        ->and($restore->source_destination_id)->toBe($this->offsite->id)
        ->and($restore->post_check)->toBe(['expected_tables' => 12, 'actual_tables' => 12, 'ok' => true])
        ->and($restore->age_identity)->toBeNull();

    // Only the key line was used, from a temporary file that is gone now.
    expect($this->tools->identitySeen)->toBe(IDENTITY."\n")
        ->and($this->tools->importedFrom)->toBe('AGE-ENCRYPTED:ORIGINAL')
        ->and(glob($this->root.'/tmp/*'))->toBe([]);

    Process::assertRan(fn (PendingProcess $p) => is_array($p->command)
        && in_array('-e', $p->command, true)
        && str_contains(end($p->command), 'DROP DATABASE IF EXISTS `shop_restore_20260923`; CREATE DATABASE `shop_restore_20260923` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;'));

    $import = collect($this->tools->pipelines)->first(fn ($p) => str_contains($p['command'], '${:BM_AGE}'));
    expect($import['command'])->toStartWith('set -o pipefail;')->not->toContain('shop_restore')->not->toContain('Db-Secret');
    expect((string) $restore->log)->not->toContain(IDENTITY);

    expect(AuditLog::query()->where('action', AuditAction::RestoreRequested->value)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::RestoreFinished->value)->exists())->toBeTrue();
    Notification::assertSentTo(new AnonymousNotifiable, BackupAlert::class, fn (BackupAlert $n) => $n->event === NotificationEvent::RestoreSuccess);
});

test('replace mode takes a safety backup before touching the database', function () {
    actingAsRole(Role::Admin);

    $this->post('/restores', restorePayload([
        'mode' => 'replace',
        'target_database' => 'shop',
        'confirmation' => 'shop',
        'source_copy_id' => $this->copies['local']->id,
    ]))->assertRedirect();

    $restore = RestoreJob::query()->firstOrFail();
    expect($restore->status)->toBe(RestoreStatus::Success)
        ->and($restore->safety_backup_file_id)->not->toBeNull();

    $safety = BackupFile::query()->findOrFail($restore->safety_backup_file_id);
    expect($safety->status)->toBe(BackupFileStatus::Success)
        ->and($safety->run->trigger)->toBe(RunTrigger::PreRestore)
        ->and($safety->filename)->toContain('__pre_restore.sql.zst.age')
        ->and($safety->copies()->where('status', CopyStatus::Verified->value)->count())->toBeGreaterThan(0);

    // The safety dump happened before the database was dropped and re-imported.
    expect($this->tools->sequence)->toBe(['dump', 'sql', 'import']);
});

test('a failed safety backup aborts the restore before anything is dropped', function () {
    $this->tools->dumpFails = true;
    actingAsRole(Role::Admin);

    $this->post('/restores', restorePayload(['mode' => 'replace', 'target_database' => 'shop', 'confirmation' => 'shop']));

    $restore = RestoreJob::query()->firstOrFail();
    expect($restore->status)->toBe(RestoreStatus::Failed)->and($restore->error)->toContain('Dump failed');
    expect($this->tools->sequence)->toBe(['dump']);
    Notification::assertSentTo(new AnonymousNotifiable, BackupAlert::class, fn (BackupAlert $n) => $n->event === NotificationEvent::RestoreFailed);
});

test('a copy with a bad checksum is skipped and another copy is used', function () {
    $this->tools->remote['BMDEST:'.$this->copies['offsite']->remote_path]['content'] = 'TAMPERED';
    $this->server->tableCounts['shop_restore_20260923'] = 12;
    actingAsRole(Role::Admin);

    $this->post('/restores', restorePayload());

    $restore = RestoreJob::query()->firstOrFail();
    expect($restore->status)->toBe(RestoreStatus::Success)
        ->and($restore->source_destination_id)->toBe($this->local->id)
        ->and($restore->log)->toContain('Checksum mismatch');
});

test('a table count mismatch fails the post-check', function () {
    $this->server->tableCounts['shop_restore_20260923'] = 7;
    actingAsRole(Role::Admin);

    $this->post('/restores', restorePayload());

    $restore = RestoreJob::query()->firstOrFail();
    expect($restore->status)->toBe(RestoreStatus::Failed)
        ->and($restore->post_check['ok'])->toBeFalse()
        ->and($restore->error)->toContain('expected 12 tables, found 7');
});

test('a wrong private key gives a clear error', function () {
    $this->tools->importFails = true;
    actingAsRole(Role::Admin);

    $this->post('/restores', restorePayload());

    expect(RestoreJob::query()->firstOrFail()->error)->toContain('age private key does not match');
});

test('restore requests are validated', function (array $overrides, string $field) {
    Queue::fake();
    actingAsRole(Role::Admin);

    $this->post('/restores', restorePayload($overrides))->assertSessionHasErrors($field);
    Queue::assertNothingPushed();
})->with([
    'confirmation mismatch' => [['confirmation' => 'shop_restore'], 'confirmation'],
    'replace with other name' => [['mode' => 'replace', 'target_database' => 'other', 'confirmation' => 'other'], 'target_database'],
    'new copy over existing db' => [['target_database' => 'shop', 'confirmation' => 'shop'], 'target_database'],
    'system database' => [['target_database' => 'mysql', 'confirmation' => 'mysql'], 'target_database'],
    'bad name' => [['target_database' => 'x;drop', 'confirmation' => 'x;drop'], 'target_database'],
    'no key' => [['age_identity' => ''], 'age_identity'],
    'not a key' => [['age_identity' => 'hello'], 'age_identity'],
]);

test('the server identity file is used when no key is pasted', function () {
    $keyFile = $this->root.'-key.txt';
    file_put_contents($keyFile, IDENTITY."\n");
    config(['backup-manager.age_identity_file' => $keyFile]);
    $this->server->tableCounts['shop_restore_20260923'] = 12;
    actingAsRole(Role::Admin);

    $this->post('/restores', restorePayload(['age_identity' => '']))->assertSessionHasNoErrors();

    expect(RestoreJob::query()->firstOrFail()->status)->toBe(RestoreStatus::Success)
        ->and(file_exists($keyFile))->toBeTrue();
});

test('viewers cannot restore', function () {
    actingAsRole(Role::Viewer);

    $this->post('/restores', restorePayload())->assertForbidden();
    $this->get('/restores/new')->assertForbidden();
    $this->get('/restores')->assertOk();
});

test('the pasted key is stored encrypted until the job starts', function () {
    Queue::fake();
    actingAsRole(Role::Admin);

    $this->post('/restores', restorePayload());

    $restore = RestoreJob::query()->firstOrFail();
    expect($restore->age_identity)->toBe(IDENTITY)
        ->and((string) DB::table('restore_jobs')->value('age_identity'))->not->toContain('AGE-SECRET-KEY');
    Queue::assertPushed(RestoreDatabaseJob::class);

    // Unused keys are wiped by the hourly reaper.
    $this->travel(2)->hours();
    $this->artisan('backup-manager:reap-stuck');
    expect($restore->fresh()->age_identity)->toBeNull();
});

test('wizard and progress pages render', function () {
    actingAsRole(Role::Operator);

    $this->get('/restores/new')->assertOk()->assertInertia(fn ($page) => $page->component('restores/create')->has('databases', 1));
    $this->get('/restores/new?database='.$this->database->id.'&file='.$this->file->id)
        ->assertInertia(fn ($page) => $page->has('timeline', 1)->where('timeline.0.copies.0.restorable', true)->where('suggestedName', 'shop_restore_'.now()->format('Ymd')));

    $this->server->tableCounts['shop_restore_20260923'] = 12;
    $this->post('/restores', restorePayload());
    $restore = RestoreJob::query()->firstOrFail();

    $this->get("/restores/{$restore->id}")->assertOk()->assertInertia(fn ($page) => $page->where('restore.status', 'success'));
});
