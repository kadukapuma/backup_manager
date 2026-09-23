<?php

use App\Enums\AuditAction;
use App\Enums\BackupFileStatus;
use App\Enums\CopyStatus;
use App\Enums\Role;
use App\Jobs\DeleteBackupFileJob;
use App\Models\AuditLog;
use App\Models\BackupCopy;
use App\Models\BackupFile;
use App\Models\BackupPlan;
use App\Models\Database;
use App\Models\Destination;
use App\Services\Retention\RetentionService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Process::fake();
    $this->db = Database::factory()->create(['name' => 'shop']);
    $this->dest = Destination::factory()->create();
    $this->plan = BackupPlan::factory()->create([
        'connection_id' => $this->db->connection_id,
        'retention' => ['keep_daily' => 3, 'keep_weekly' => 0, 'keep_monthly' => 0],
    ]);
    $this->plan->destinations()->attach($this->dest);
});

function copyAt(string $when, CopyStatus $status = CopyStatus::Verified, BackupFileStatus $fileStatus = BackupFileStatus::Success): BackupCopy
{
    $file = BackupFile::factory()->create([
        'database_id' => test()->db->id,
        'status' => $fileStatus,
        'filename' => 'shop__'.str_replace([' ', ':', '-'], '', $when).'__scheduled.sql.zst.age',
        'created_at' => $when,
    ]);

    return BackupCopy::factory()->create([
        'backup_file_id' => $file->id,
        'destination_id' => test()->dest->id,
        'remote_path' => '/var/backups/db/x/'.$file->filename,
        'status' => $status,
    ]);
}

test('retention deletes copies outside the daily window and marks them deleted', function () {
    $this->travelTo(now()->setTimezone('Asia/Colombo')->setTime(12, 0));
    $keep = [copyAt(now()->toDateString().' 02:00:00'), copyAt(now()->subDay()->toDateString().' 02:00:00'), copyAt(now()->subDays(2)->toDateString().' 02:00:00')];
    $old = [copyAt(now()->subDays(3)->toDateString().' 02:00:00'), copyAt(now()->subDays(10)->toDateString().' 02:00:00')];

    $deleted = app(RetentionService::class)->apply($this->db);

    expect($deleted)->toBe(2);
    foreach ($keep as $c) {
        expect($c->fresh()->status)->toBe(CopyStatus::Verified);
    }
    foreach ($old as $c) {
        expect($c->fresh()->status)->toBe(CopyStatus::Deleted)->and($c->fresh()->deleted_at)->not->toBeNull();
    }

    // Both the file and its manifest are removed remotely.
    Process::assertRanTimes(fn (PendingProcess $p) => in_array('deletefile', (array) $p->command, true), 4);
    expect(AuditLog::query()->where('action', AuditAction::RetentionApplied->value)->exists())->toBeTrue();
});

test('failed backups and failed copies are ignored by retention', function () {
    $recent = copyAt(now()->toDateString().' 02:00:00');
    $failedFile = copyAt(now()->subDays(20)->toDateString().' 02:00:00', CopyStatus::Verified, BackupFileStatus::Failed);
    $failedCopy = copyAt(now()->subDays(20)->toDateString().' 03:00:00', CopyStatus::Failed);

    app(RetentionService::class)->apply($this->db);

    expect($recent->fresh()->status)->toBe(CopyStatus::Verified)
        ->and($failedFile->fresh()->status)->toBe(CopyStatus::Verified)
        ->and($failedCopy->fresh()->status)->toBe(CopyStatus::Failed);
});

test('the newest backup survives even when it is old', function () {
    $only = copyAt(now()->subYear()->toDateString().' 02:00:00');

    app(RetentionService::class)->apply($this->db);

    expect($only->fresh()->status)->toBe(CopyStatus::Verified);
});

test('destinations without a covering plan are not pruned', function () {
    $other = Destination::factory()->create();
    $old = copyAt(now()->subDays(30)->toDateString().' 02:00:00');
    $old->update(['destination_id' => $other->id]);
    copyAt(now()->toDateString().' 02:00:00');

    app(RetentionService::class)->apply($this->db);

    expect($old->fresh()->status)->toBe(CopyStatus::Verified);
});

test('the most generous overlapping plan wins', function () {
    $generous = BackupPlan::factory()->create([
        'connection_id' => $this->db->connection_id,
        'retention' => ['keep_daily' => 30, 'keep_weekly' => 0, 'keep_monthly' => 0],
    ]);
    $generous->destinations()->attach($this->dest);
    copyAt(now()->toDateString().' 02:00:00');
    $tenDaysOld = copyAt(now()->subDays(10)->toDateString().' 02:00:00');

    app(RetentionService::class)->apply($this->db);

    expect($tenDaysOld->fresh()->status)->toBe(CopyStatus::Verified);
});

test('retention skips a database that is being backed up or restored', function () {
    copyAt(now()->toDateString().' 02:00:00');
    $old = copyAt(now()->subDays(10)->toDateString().' 02:00:00');
    $lock = Cache::lock($this->db->lockKey(), 60);
    $lock->get();

    expect(app(RetentionService::class)->apply($this->db))->toBe(-1)
        ->and($old->fresh()->status)->toBe(CopyStatus::Verified);
    $lock->release();
});

test('admins can delete a backup; operators cannot', function () {
    Queue::fake();
    $copy = copyAt(now()->toDateString().' 02:00:00');

    actingAsRole(Role::Operator);
    $this->delete("/backups/{$copy->backup_file_id}")->assertForbidden();

    actingAsRole(Role::Admin);
    $this->delete("/backups/{$copy->backup_file_id}")->assertSessionHas('success');
    Queue::assertPushed(DeleteBackupFileJob::class);
    expect(AuditLog::query()->where('action', AuditAction::BackupDeleted->value)->exists())->toBeTrue();

    (new DeleteBackupFileJob($copy->backup_file_id))->handle(app(RetentionService::class));
    expect($copy->fresh()->status)->toBe(CopyStatus::Deleted);
});
