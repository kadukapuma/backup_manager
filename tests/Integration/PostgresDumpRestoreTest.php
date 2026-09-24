<?php

/*
 * Real end-to-end test for PostgreSQL: pg_dump | zstd | age -> local
 * destination -> restore into a new database with psql, optionally through
 * an SSH tunnel. Skipped unless RUN_PG_INTEGRATION=true.
 *
 * Requirements on the machine running the test: pg_dump, psql, zstd, age,
 * age-keygen, rclone (and ssh, ssh-keyscan for the SSH variant).
 *
 *   RUN_PG_INTEGRATION=true IT_PG_HOST=127.0.0.1 IT_PG_PORT=5432 \
 *   IT_PG_USER=bm_backup IT_PG_PASSWORD=secret ./vendor/bin/pest --testsuite=Integration
 *
 * Through SSH, add (IT_PG_HOST/PORT are then as seen from the SSH server):
 *
 *   IT_SSH_HOST=203.0.113.20 IT_SSH_PORT=22 IT_SSH_USER=bmtunnel IT_SSH_KEY_FILE=/path/to/private_key
 *
 * The user needs CREATEDB. The test creates and drops databases named bm_it_pg_*.
 */

use App\Actions\Backups\StartBackupRun;
use App\Actions\Restores\StartRestore;
use App\Enums\BackupFileStatus;
use App\Enums\ConnectionDriver;
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
use App\Services\Database\PgEnv;
use App\Services\Settings\SettingsStore;
use App\Services\Ssh\SshHostKeys;
use App\Services\Ssh\SshTunnel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Process;

/**
 * Runs SQL with psql against $database, through the tunnel when the connection has one.
 */
function itPsql(ServerConnection $connection, string $database, string $sql): string
{
    return app(SshTunnel::class)->with($connection, fn (ServerConnection $c): string => PgEnv::with($c, $database, function (array $env) use ($sql): string {
        return Process::env($env)->run([(string) config('backup-manager.binaries.psql'), '-X', '-A', '-t', '-q', '-w', '-v', 'ON_ERROR_STOP=1', '-c', $sql])
            ->throw()
            ->output();
    }));
}

beforeEach(function () {
    if (env('RUN_PG_INTEGRATION') !== 'true') {
        $this->markTestSkipped('Set RUN_PG_INTEGRATION=true to run the real PostgreSQL dump/restore test.');
    }

    $this->work = sys_get_temp_dir().'/bm-it-pg-'.bin2hex(random_bytes(4));
    mkdir($this->work.'/dest', 0700, true);
    config([
        'backup-manager.staging_path' => $this->work.'/staging',
        'backup-manager.tmp_path' => $this->work.'/tmp',
    ]);

    $keyFile = $this->work.'/key.txt';
    Process::run(['age-keygen', '-o', $keyFile])->throw();
    preg_match('/public key: (age1[0-9a-z]+)/', (string) file_get_contents($keyFile), $m);
    app(SettingsStore::class)->set(SettingsStore::AGE_PUBLIC_KEY, $m[1]);
    preg_match('/AGE-SECRET-KEY-1[0-9A-Z]+/', (string) file_get_contents($keyFile), $k);
    $this->identity = $k[0];

    $this->connection = ServerConnection::factory()->create([
        'name' => 'it-pg',
        'driver' => ConnectionDriver::Pgsql,
        'host' => env('IT_PG_HOST', '127.0.0.1'),
        'port' => (int) env('IT_PG_PORT', 5432),
        'username' => env('IT_PG_USER', 'postgres'),
        'password' => env('IT_PG_PASSWORD', ''),
    ]);

    if ((string) env('IT_SSH_HOST', '') !== '') {
        $this->connection->forceFill([
            'ssh_enabled' => true,
            'ssh_host' => env('IT_SSH_HOST'),
            'ssh_port' => (int) env('IT_SSH_PORT', 22),
            'ssh_user' => env('IT_SSH_USER'),
            'ssh_private_key' => (string) file_get_contents((string) env('IT_SSH_KEY_FILE')),
        ])->save();
        app(SshHostKeys::class)->pinIfMissing($this->connection);
    }

    $this->source = 'bm_it_pg_src_'.bin2hex(random_bytes(3));
    $this->target = 'bm_it_pg_rst_'.bin2hex(random_bytes(3));

    itPsql($this->connection, 'postgres', "CREATE DATABASE \"{$this->source}\" TEMPLATE template0 ENCODING 'UTF8'");
    itPsql($this->connection, $this->source, "CREATE TABLE customers (id serial PRIMARY KEY, name varchar(100), note text);
        CREATE TABLE orders (id int PRIMARY KEY, customer_id int REFERENCES customers(id), total numeric(10,2));
        INSERT INTO customers (name, note) SELECT 'Customer ' || g, 'Unicode ✓ කොළඹ ' || g FROM generate_series(1, 250) g;
        INSERT INTO orders VALUES (1, 42, 99.50);");
});

afterEach(function () {
    if (isset($this->connection, $this->source)) {
        itPsql($this->connection, 'postgres', "DROP DATABASE IF EXISTS \"{$this->source}\"");
        itPsql($this->connection, 'postgres', "DROP DATABASE IF EXISTS \"{$this->target}\"");
    }
});

test('real PostgreSQL backup is encrypted, stored, and restores into a new database', function () {
    $database = Database::factory()->create(['connection_id' => $this->connection->id, 'name' => $this->source, 'table_count' => 2, 'default_charset' => 'UTF8']);
    $destination = Destination::factory()->create(['base_path' => $this->work.'/dest']);

    $run = app(StartBackupRun::class)->handle($this->connection, new Collection([$database]), new Collection([$destination]), RunTrigger::Manual);

    $file = BackupFile::query()->firstOrFail();
    expect($run->fresh()->status)->toBe(RunStatus::Success, (string) $file->log)
        ->and($file->status)->toBe(BackupFileStatus::Success)
        ->and($file->manifest['engine'])->toBe('pgsql');

    $copy = $file->copies()->firstOrFail();
    expect($copy->status)->toBe(CopyStatus::Verified)
        ->and((string) file_get_contents($copy->remote_path, false, null, 0, 40))->toStartWith('age-encryption.org/v1');

    $user = userWithRole('admin');
    $restore = app(StartRestore::class)->handle($file, $copy->id, $this->connection->id, $this->target, RestoreMode::NewCopy, $this->identity, $user);

    $restore = RestoreJob::query()->findOrFail($restore->id);
    expect($restore->status)->toBe(RestoreStatus::Success, (string) $restore->error."\n".$restore->log)
        ->and($restore->post_check['actual_tables'])->toBe(2);

    expect(trim(itPsql($this->connection, $this->target, 'SELECT count(*) FROM customers')))->toBe('250')
        ->and(trim(itPsql($this->connection, $this->target, 'SELECT note FROM customers WHERE id = 42')))->toBe('Unicode ✓ කොළඹ 42')
        ->and(trim(itPsql($this->connection, $this->target, 'SELECT total FROM orders WHERE id = 1')))->toBe('99.50');
});
