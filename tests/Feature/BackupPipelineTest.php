<?php

use App\Actions\Backups\StartBackupRun;
use App\Enums\AuditAction;
use App\Enums\BackupFileStatus;
use App\Enums\CopyStatus;
use App\Enums\NotificationEvent;
use App\Enums\Role;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\VerificationLevel;
use App\Exceptions\BackupException;
use App\Jobs\BackupDatabaseJob;
use App\Models\AuditLog;
use App\Models\BackupFile;
use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Models\Database;
use App\Models\Destination;
use App\Models\NotificationChannel;
use App\Models\ServerConnection;
use App\Models\VerificationRun;
use App\Notifications\BackupAlert;
use App\Services\Settings\SettingsStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeBackupTools;

const AGE_KEY = 'age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p';

beforeEach(function () {
    $root = sys_get_temp_dir().'/bm-pipeline-'.uniqid();
    config([
        'backup-manager.staging_path' => $root.'/staging',
        'backup-manager.tmp_path' => $root.'/tmp',
        'backup-manager.lock_wait_attempts' => 0,
    ]);
    $this->root = $root;
    app(SettingsStore::class)->set(SettingsStore::AGE_PUBLIC_KEY, AGE_KEY);
    Notification::fake();
    NotificationChannel::factory()->create(['events' => ['run.failed', 'run.partial', 'run.success']]);

    $this->tools = FakeBackupTools::install();
    $this->connection = ServerConnection::factory()->create(['name' => 'VPS Main', 'password' => 'Db-Secret-Pass']);
    $this->database = Database::factory()->create(['connection_id' => $this->connection->id, 'name' => 'shop', 'table_count' => 12]);
    $this->local = Destination::factory()->create(['name' => 'local', 'base_path' => '/var/backups/db']);
});

function startRun(array $destinations, ?Collection $databases = null): BackupRun
{
    return app(StartBackupRun::class)->handle(
        test()->connection,
        $databases ?? new Collection([test()->database]),
        new Collection($destinations),
        RunTrigger::Manual,
    );
}

test('a successful backup dumps, verifies, encrypts, uploads and verifies remotely', function () {
    $run = startRun([$this->local]);

    $run->refresh();
    $file = BackupFile::query()->firstOrFail();

    expect($run->status)->toBe(RunStatus::Success)
        ->and($file->status)->toBe(BackupFileStatus::Success)
        ->and($file->filename)->toMatch('/^shop__\d{8}-\d{6}__manual\.sql\.zst\.age$/')
        ->and($file->sha256)->toBe(hash('sha256', 'AGE-ENCRYPTED:ZSTD-DATA-shop'))
        ->and($file->manifest['table_count'])->toBe(12)
        ->and($file->manifest['age_recipient'])->toBe(AGE_KEY)
        ->and($file->manifest['connection'])->toBe('VPS Main');

    $copy = $file->copies()->firstOrFail();
    expect($copy->status)->toBe(CopyStatus::Verified)
        ->and($copy->remote_path)->toBe('/var/backups/db/vps_main/shop/'.$file->filename);

    // Manifest uploaded next to the file.
    expect($this->tools->remote)->toHaveKey('BMDEST:/var/backups/db/vps_main/shop/'.$file->filename.'.manifest.json');

    expect(VerificationRun::query()->where('level', VerificationLevel::Checksum->value)->count())->toBe(1)
        ->and(VerificationRun::query()->where('level', VerificationLevel::Remote->value)->count())->toBe(1);

    expect($this->database->fresh()->last_success_at)->not->toBeNull();
    expect(AuditLog::query()->where('action', AuditAction::BackupRunFinished->value)->exists())->toBeTrue();

    // Staging and temporary files are cleaned up.
    expect(glob($this->root.'/staging/*'))->toBe([])
        ->and(glob($this->root.'/tmp/*'))->toBe([]);
});

test('the dump command uses placeholders, a defaults file and never the password', function () {
    startRun([$this->local]);

    $dump = collect($this->tools->pipelines)->first(fn ($p) => str_contains($p['command'], '${:BM_DUMP}'));

    expect($dump['command'])
        ->toStartWith('set -o pipefail;')
        ->toContain('--defaults-extra-file="${:BM_CNF}"')
        ->toContain('--single-transaction --quick --routines --triggers --events --no-tablespaces')
        ->toContain('"${:BM_DB}"')
        ->not->toContain('shop')
        ->not->toContain('Db-Secret-Pass');
    expect($dump['env']['BM_DB'])->toBe('shop')
        ->and(json_encode($dump['env']))->not->toContain('Db-Secret-Pass');

    // The credentials were in the 0600 defaults file while the dump ran, and it is gone now.
    expect($dump['cnf'])->toContain('password="Db-Secret-Pass"');
    expect(file_exists($dump['env']['BM_CNF']))->toBeFalse();
});

test('a failed dump fails the run, keeps secrets out of the log and alerts', function () {
    $this->tools->dumpFails = true;

    $run = startRun([$this->local]);

    $file = BackupFile::query()->firstOrFail();
    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and($file->status)->toBe(BackupFileStatus::Failed)
        ->and($file->error)->toContain('Access denied')
        ->and((string) $file->log)->not->toContain('Db-Secret-Pass');

    Notification::assertSentTo(new AnonymousNotifiable, BackupAlert::class, fn (BackupAlert $n) => $n->event === NotificationEvent::RunFailed);
});

test('an incomplete dump is rejected before encryption', function () {
    $this->tools->dumpIncomplete = true;

    startRun([$this->local]);

    $file = BackupFile::query()->firstOrFail();
    expect($file->status)->toBe(BackupFileStatus::Failed)
        ->and($file->error)->toContain('Dump completed');
    expect(collect($this->tools->remote))->toBeEmpty();
});

test('one failing destination makes the run partial', function () {
    $offsite = Destination::factory()->sftp()->create(['name' => 'offsite', 'base_path' => 'backups']);
    $this->tools->failingRemotes = ['BMDEST:backups/'];

    $run = startRun([$this->local, $offsite]);

    expect($run->fresh()->status)->toBe(RunStatus::Partial);
    $statuses = BackupFile::query()->firstOrFail()->copies()->with('destination')->get()
        ->mapWithKeys(fn ($c) => [$c->destination->name => $c->status])->all();
    expect($statuses)->toBe(['local' => CopyStatus::Verified, 'offsite' => CopyStatus::Failed]);

    Notification::assertSentTo(new AnonymousNotifiable, BackupAlert::class, fn (BackupAlert $n) => $n->event === NotificationEvent::RunPartial);
});

test('a remote hash mismatch fails the copy and keeps the file in staging when nothing was stored', function () {
    $this->tools->remoteHashMismatch = true;

    $run = startRun([$this->local]);

    $file = BackupFile::query()->firstOrFail();
    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and($file->copies()->first()->status)->toBe(CopyStatus::Failed)
        ->and($file->copies()->first()->error)->toContain('SHA-256 mismatch')
        ->and(glob($this->root.'/staging/*.age'))->toHaveCount(1);
});

test('a database that is locked by another job is not backed up', function () {
    $lock = Cache::lock($this->database->lockKey(), 60);
    $lock->get();

    startRun([$this->local]);

    $file = BackupFile::query()->firstOrFail();
    expect($file->status)->toBe(BackupFileStatus::Failed)
        ->and($file->error)->toContain('still running');
    expect(collect($this->tools->pipelines))->toBeEmpty();
    $lock->release();
});

test('a run cannot start without an age public key', function () {
    app(SettingsStore::class)->set(SettingsStore::AGE_PUBLIC_KEY, null);

    startRun([$this->local]);
})->throws(BackupException::class, 'age public key');

test('the scheduler dispatches due plans once and moves next_run_at forward', function () {
    Queue::fake();
    $plan = BackupPlan::factory()->create(['connection_id' => $this->connection->id, 'next_run_at' => now()->subMinute()]);
    $plan->destinations()->attach($this->local);
    BackupPlan::factory()->create(['connection_id' => $this->connection->id, 'next_run_at' => now()->addHour()]);

    $this->artisan('backup-manager:dispatch-due')->assertSuccessful();

    expect(BackupRun::query()->count())->toBe(1)
        ->and(BackupRun::query()->first()->trigger)->toBe(RunTrigger::Scheduled)
        ->and($plan->fresh()->next_run_at->isFuture())->toBeTrue();
    Queue::assertPushed(BackupDatabaseJob::class, 1);

    $this->artisan('backup-manager:dispatch-due');
    expect(BackupRun::query()->count())->toBe(1);
});

test('a plan that cannot start records a failed run and alerts', function () {
    app(SettingsStore::class)->set(SettingsStore::AGE_PUBLIC_KEY, null);
    $plan = BackupPlan::factory()->create(['connection_id' => $this->connection->id, 'next_run_at' => now()->subMinute()]);
    $plan->destinations()->attach($this->local);

    $this->artisan('backup-manager:dispatch-due');

    $run = BackupRun::query()->firstOrFail();
    expect($run->status)->toBe(RunStatus::Failed)->and($run->summary['error'])->toContain('age public key');
    Notification::assertSentTo(new AnonymousNotifiable, BackupAlert::class, fn (BackupAlert $n) => $n->event === NotificationEvent::RunFailed);
});

test('stuck files are reaped', function () {
    $run = BackupRun::factory()->create(['status' => RunStatus::Running]);
    $file = BackupFile::factory()->create(['backup_run_id' => $run->id, 'status' => BackupFileStatus::Running]);
    BackupFile::query()->whereKey($file->id)->update(['updated_at' => now()->subDays(2)]);

    $this->artisan('backup-manager:reap-stuck');

    expect($file->fresh()->status)->toBe(BackupFileStatus::Failed)->and($run->fresh()->status)->toBe(RunStatus::Failed);
});

test('operators can back up now; viewers cannot; excluded databases are skipped', function () {
    Queue::fake();
    $excluded = Database::factory()->excluded()->create(['connection_id' => $this->connection->id]);

    actingAsRole(Role::Viewer);
    $this->post('/backups/manual', ['database_ids' => [$this->database->id]])->assertForbidden();

    actingAsRole(Role::Operator);
    $this->post('/backups/manual', ['database_ids' => [$this->database->id, $excluded->id]])
        ->assertRedirect()->assertSessionHas('success');

    expect(BackupFile::query()->pluck('database_id')->all())->toBe([$this->database->id]);
    Queue::assertPushed(BackupDatabaseJob::class, 1);
});

test('run pages render', function () {
    $run = startRun([$this->local]);
    actingAsRole(Role::Viewer);

    $this->get('/runs')->assertOk()->assertInertia(fn ($page) => $page->component('runs/index')->has('runs.data', 1));
    $this->get("/runs/{$run->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('runs/show')
        ->where('run.status', 'success')
        ->has('files.0.copies', 1));
});

test('only admins can download, and only from a local copy', function () {
    startRun([$this->local]);
    $file = BackupFile::query()->firstOrFail();
    $copy = $file->copies()->firstOrFail();

    $dir = $this->root.'/local-dest';
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/'.$file->filename, 'AGE-ENCRYPTED');
    $copy->update(['remote_path' => $dir.'/'.$file->filename]);

    actingAsRole(Role::Operator);
    $this->get("/backups/{$file->id}/download")->assertForbidden();

    actingAsRole(Role::Admin);
    $this->get("/backups/{$file->id}/download")->assertOk()->assertDownload($file->filename);
    expect(AuditLog::query()->where('action', AuditAction::BackupDownloaded->value)->exists())->toBeTrue();
});
