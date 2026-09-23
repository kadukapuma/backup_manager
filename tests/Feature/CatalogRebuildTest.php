<?php

use App\Enums\AuditAction;
use App\Enums\BackupFileStatus;
use App\Enums\CopyStatus;
use App\Enums\Role;
use App\Jobs\RebuildCatalogJob;
use App\Models\AuditLog;
use App\Models\BackupCopy;
use App\Models\BackupFile;
use App\Models\Database;
use App\Models\Destination;
use App\Models\ServerConnection;
use App\Services\Catalog\CatalogRebuilder;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * Fakes `rclone lsjson -R` and `rclone cat` for a destination holding the given files.
 *
 * @param  array<string, string>  $files  path relative to base => content
 */
function fakeRemote(Destination $dest, array $files): void
{
    Process::fake(function (PendingProcess $p) use ($dest, $files) {
        $args = (array) $p->command;
        if (in_array('lsjson', $args, true)) {
            return Process::result(json_encode(array_map(
                fn (string $path, string $content) => ['Path' => $path, 'Name' => basename($path), 'Size' => strlen($content), 'IsDir' => false],
                array_keys($files),
                $files,
            )));
        }
        if (in_array('cat', $args, true)) {
            $spec = $args[array_search('cat', $args, true) + 1];
            $relative = substr($spec, strlen('BMDEST:'.rtrim($dest->base_path, '/').'/'));

            return Process::result($files[$relative] ?? '');
        }

        return Process::result('');
    });
}

function manifestFor(string $db, string $connection, string $filename, string $content, int $tables = 5): string
{
    return json_encode([
        'format_version' => 1,
        'filename' => $filename,
        'database' => $db,
        'connection' => $connection,
        'created_at' => '2026-05-01T02:00:00+05:30',
        'trigger' => 'scheduled',
        'size_bytes' => strlen($content),
        'sha256' => hash('sha256', $content),
        'md5' => md5($content),
        'table_count' => $tables,
    ]);
}

test('a fresh install rebuilds files and copies from manifests', function () {
    $conn = ServerConnection::factory()->create(['name' => 'VPS Main']);
    $dest = Destination::factory()->create(['base_path' => '/var/backups/db']);
    $a = 'ENCRYPTED-A';
    $b = 'ENCRYPTED-B';
    fakeRemote($dest, [
        'vps_main/shop/shop__20260501-020000__scheduled.sql.zst.age' => $a,
        'vps_main/shop/shop__20260501-020000__scheduled.sql.zst.age.manifest.json' => manifestFor('shop', 'VPS Main', 'shop__20260501-020000__scheduled.sql.zst.age', $a, 12),
        'old_server/legacy/legacy__20260401-020000__manual.sql.zst.age' => $b,
        'old_server/legacy/legacy__20260401-020000__manual.sql.zst.age.manifest.json' => manifestFor('legacy', 'Old Server', 'legacy__20260401-020000__manual.sql.zst.age', $b),
        'notes.txt' => 'ignore me',
    ]);

    $result = app(CatalogRebuilder::class)->rebuild($dest);

    expect($result)->toMatchArray(['manifests' => 2, 'files_created' => 2, 'copies_linked' => 2, 'skipped' => 0]);

    $shop = Database::query()->where('name', 'shop')->firstOrFail();
    expect($shop->connection_id)->toBe($conn->id);
    $file = BackupFile::query()->where('database_id', $shop->id)->firstOrFail();
    expect($file->status)->toBe(BackupFileStatus::Success)
        ->and($file->sha256)->toBe(hash('sha256', $a))
        ->and($file->manifest['table_count'])->toBe(12)
        ->and($file->created_at->format('Y-m-d H:i'))->toBe('2026-05-01 02:00');

    $copy = BackupCopy::query()->where('backup_file_id', $file->id)->firstOrFail();
    expect($copy->remote_path)->toBe('/var/backups/db/vps_main/shop/shop__20260501-020000__scheduled.sql.zst.age')
        ->and($copy->status)->toBe(CopyStatus::Uploaded);

    // Unknown connection names land on an inactive placeholder connection.
    $legacy = Database::query()->where('name', 'legacy')->firstOrFail();
    expect($legacy->serverConnection->name)->toBe(CatalogRebuilder::PLACEHOLDER_CONNECTION)
        ->and($legacy->serverConnection->is_active)->toBeFalse();
});

test('rebuilding twice does not duplicate records', function () {
    ServerConnection::factory()->create(['name' => 'VPS Main']);
    $dest = Destination::factory()->create(['base_path' => '/var/backups/db']);
    $a = 'ENCRYPTED-A';
    fakeRemote($dest, [
        'vps_main/shop/shop__20260501-020000__scheduled.sql.zst.age' => $a,
        'vps_main/shop/shop__20260501-020000__scheduled.sql.zst.age.manifest.json' => manifestFor('shop', 'VPS Main', 'shop__20260501-020000__scheduled.sql.zst.age', $a),
    ]);

    app(CatalogRebuilder::class)->rebuild($dest);
    $second = app(CatalogRebuilder::class)->rebuild($dest);

    expect($second['files_created'])->toBe(0)
        ->and(BackupFile::query()->count())->toBe(1)
        ->and(BackupCopy::query()->count())->toBe(1);
});

test('bad manifests are skipped with a reason', function () {
    $dest = Destination::factory()->create(['base_path' => '/var/backups/db']);
    fakeRemote($dest, [
        'x/a/a__20260501-020000__scheduled.sql.zst.age.manifest.json' => '{not json',
        'x/b/b__20260501-020000__scheduled.sql.zst.age.manifest.json' => manifestFor('b', 'X', 'b__20260501-020000__scheduled.sql.zst.age', 'data'),
        'x/c/c__20260501-020000__scheduled.sql.zst.age' => 'short',
        'x/c/c__20260501-020000__scheduled.sql.zst.age.manifest.json' => manifestFor('c', 'X', 'c__20260501-020000__scheduled.sql.zst.age', 'longer content'),
        'x/d/d__20260501-020000__scheduled.sql.zst.age' => 'd',
        'x/d/d__20260501-020000__scheduled.sql.zst.age.manifest.json' => manifestFor('../etc', 'X', 'd__20260501-020000__scheduled.sql.zst.age', 'd'),
    ]);

    $result = app(CatalogRebuilder::class)->rebuild($dest);

    expect($result['skipped'])->toBe(4)
        ->and($result['files_created'])->toBe(0)
        ->and(implode("\n", $result['errors']))->toContain('not valid JSON')->toContain('missing')->toContain('Size mismatch');
});

test('only admins can request a rebuild', function () {
    Queue::fake();
    $dest = Destination::factory()->create();

    actingAsRole(Role::Operator);
    $this->post('/system/rebuild-catalog', ['destination_id' => $dest->id])->assertForbidden();

    actingAsRole(Role::Admin);
    $this->post('/system/rebuild-catalog', ['destination_id' => $dest->id])->assertSessionHas('success');
    Queue::assertPushed(RebuildCatalogJob::class);
    expect(AuditLog::query()->where('action', AuditAction::CatalogRebuildRequested->value)->exists())->toBeTrue();
});
