<?php

/*
 * Real end-to-end test: mariadb-dump | zstd | age -> local destination ->
 * restore into a new database. Skipped unless RUN_INTEGRATION=true.
 *
 * Requirements: a MariaDB server, and mariadb-dump, mariadb, zstd, age,
 * age-keygen and rclone on PATH (or MARIADB_BIN_DIR / BM_*_BIN set).
 *
 *   RUN_INTEGRATION=true IT_DB_HOST=127.0.0.1 IT_DB_PORT=3306 \
 *   IT_DB_USER=root IT_DB_PASSWORD=secret ./vendor/bin/pest --testsuite=Integration
 *
 * The test creates and drops databases named bm_it_*.
 */

use App\Actions\Backups\StartBackupRun;
use App\Actions\Restores\StartRestore;
use App\Enums\BackupFileStatus;
use App\Enums\CopyStatus;
use App\Enums\RestoreMode;
use App\Enums\RestoreStatus;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\BackupFile;
use App\Models\Database;
use App\Models\Destination;
use App\Models\RestoreJob;
use App\Models\ServerConnection;
use App\Services\Settings\SettingsStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    if (env('RUN_INTEGRATION') !== 'true') {
        $this->markTestSkipped('Set RUN_INTEGRATION=true to run the real dump/restore test.');
    }

    $this->work = sys_get_temp_dir().'/bm-it-'.bin2hex(random_bytes(4));
    mkdir($this->work.'/dest', 0700, true);
    config([
        'backup-manager.staging_path' => $this->work.'/staging',
        'backup-manager.tmp_path' => $this->work.'/tmp',
    ]);

    // Throwaway age key pair.
    $keyFile = $this->work.'/key.txt';
    Process::run(['age-keygen', '-o', $keyFile])->throw();
    preg_match('/public key: (age1[0-9a-z]+)/', (string) file_get_contents($keyFile), $m);
    app(SettingsStore::class)->set(SettingsStore::AGE_PUBLIC_KEY, $m[1]);
    preg_match('/AGE-SECRET-KEY-1[0-9A-Z]+/', (string) file_get_contents($keyFile), $k);
    $this->identity = $k[0];

    $this->pdo = new PDO(
        'mysql:host='.env('IT_DB_HOST', '127.0.0.1').';port='.env('IT_DB_PORT', 3306),
        (string) env('IT_DB_USER', 'root'),
        (string) env('IT_DB_PASSWORD', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $this->source = 'bm_it_src_'.bin2hex(random_bytes(3));
    $this->target = 'bm_it_rst_'.bin2hex(random_bytes(3));
    $this->pdo->exec("CREATE DATABASE `{$this->source}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $this->pdo->exec("CREATE TABLE `{$this->source}`.customers (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(100), note TEXT)");
    $this->pdo->exec("CREATE TABLE `{$this->source}`.orders (id INT PRIMARY KEY, customer_id INT, total DECIMAL(10,2))");
    $insert = $this->pdo->prepare("INSERT INTO `{$this->source}`.customers (name, note) VALUES (?, ?)");
    for ($i = 1; $i <= 250; $i++) {
        $insert->execute(["Customer {$i}", "Unicode ✓ කොළඹ {$i}"]);
    }
});

afterEach(function () {
    if (isset($this->pdo)) {
        $this->pdo->exec("DROP DATABASE IF EXISTS `{$this->source}`");
        $this->pdo->exec("DROP DATABASE IF EXISTS `{$this->target}`");
    }
});

test('real backup is encrypted, stored, and restores into a new database', function () {
    $connection = ServerConnection::factory()->create([
        'host' => env('IT_DB_HOST', '127.0.0.1'),
        'port' => (int) env('IT_DB_PORT', 3306),
        'username' => env('IT_DB_USER', 'root'),
        'password' => env('IT_DB_PASSWORD', ''),
    ]);
    $database = Database::factory()->create(['connection_id' => $connection->id, 'name' => $this->source, 'table_count' => 2]);
    $destination = Destination::factory()->create(['base_path' => $this->work.'/dest']);

    $run = app(StartBackupRun::class)->handle($connection, new Collection([$database]), new Collection([$destination]), RunTrigger::Manual);

    $file = BackupFile::query()->firstOrFail();
    expect($run->fresh()->status)->toBe(RunStatus::Success, (string) $file->log)
        ->and($file->status)->toBe(BackupFileStatus::Success);

    $copy = $file->copies()->firstOrFail();
    expect($copy->status)->toBe(CopyStatus::Verified)
        ->and(is_file($copy->remote_path))->toBeTrue()
        ->and(hash_file('sha256', $copy->remote_path))->toBe($file->sha256);

    // The stored file is really age-encrypted, not plain SQL.
    expect((string) file_get_contents($copy->remote_path, false, null, 0, 40))->toStartWith('age-encryption.org/v1');

    $user = userWithRole('admin');
    $restore = app(StartRestore::class)->handle($file, $copy->id, $connection->id, $this->target, RestoreMode::NewCopy, $this->identity, $user);

    $restore = RestoreJob::query()->findOrFail($restore->id);
    expect($restore->status)->toBe(RestoreStatus::Success, (string) $restore->error."\n".$restore->log)
        ->and($restore->post_check['actual_tables'])->toBe(2);

    $count = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$this->target}`.customers")->fetchColumn();
    $note = (string) $this->pdo->query("SELECT note FROM `{$this->target}`.customers WHERE id = 42")->fetchColumn();
    expect($count)->toBe(250)->and($note)->toBe('Unicode ✓ කොළඹ 42');
});
