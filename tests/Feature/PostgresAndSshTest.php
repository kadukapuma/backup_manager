<?php

use App\Actions\Backups\StartBackupRun;
use App\Enums\BackupFileStatus;
use App\Enums\ConnectionDriver;
use App\Enums\RestoreStatus;
use App\Enums\Role;
use App\Enums\RunTrigger;
use App\Exceptions\BackupException;
use App\Jobs\DiscoverDatabasesJob;
use App\Jobs\RestoreDatabaseJob;
use App\Models\BackupCopy;
use App\Models\BackupFile;
use App\Models\BackupRun;
use App\Models\Database;
use App\Models\Destination;
use App\Models\RestoreJob;
use App\Models\ServerConnection;
use App\Services\Database\DatabaseServerClient;
use App\Services\Database\PgEnv;
use App\Services\Database\PostgresServerClient;
use App\Services\Settings\SettingsStore;
use App\Services\Ssh\SshHostKeys;
use App\Services\Ssh\SshTunnel;
use App\Services\Tools\ToolRegistry;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeBackupTools;
use Tests\Fakes\FakeDatabaseServerClient;

const PG_AGE_KEY = 'age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p';
const PG_IDENTITY = 'AGE-SECRET-KEY-1QQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQ';
const PG_TAIL = "COPY public.t (id) FROM stdin;\n1\n\\.\n\n--\n-- PostgreSQL database dump complete\n--\n\n";

beforeEach(function () {
    $root = sys_get_temp_dir().'/bm-pg-'.uniqid();
    config([
        'backup-manager.staging_path' => $root.'/staging',
        'backup-manager.tmp_path' => $root.'/tmp',
        'backup-manager.lock_wait_attempts' => 0,
        'backup-manager.age_identity_file' => null,
    ]);
    $this->root = $root;
    app(SettingsStore::class)->set(SettingsStore::AGE_PUBLIC_KEY, PG_AGE_KEY);
    Notification::fake();

    $this->tools = FakeBackupTools::install();
    $this->tools->sqlTail = PG_TAIL;

    $this->pg = ServerConnection::factory()->create([
        'name' => 'apps-server',
        'driver' => ConnectionDriver::Pgsql,
        'port' => 5432,
        'username' => 'bm_backup',
        'password' => 'Pg:Secret\\Pass',
    ]);
    $this->database = Database::factory()->create([
        'connection_id' => $this->pg->id,
        'name' => 'fixflow',
        'table_count' => 7,
        'default_charset' => 'UTF8',
        'default_collation' => 'en_US.UTF-8',
    ]);
    $this->local = Destination::factory()->create(['name' => 'local', 'base_path' => '/var/backups/db']);
});

function pgBackup(): BackupFile
{
    app(StartBackupRun::class)->handle(
        test()->pg,
        new Collection([test()->database]),
        new Collection([test()->local]),
        RunTrigger::Manual,
    );

    return BackupFile::query()->latest('id')->firstOrFail();
}

test('a PostgreSQL backup runs pg_dump with a password file and records the engine', function () {
    $file = pgBackup();

    expect($file->status)->toBe(BackupFileStatus::Success)
        ->and($file->manifest['engine'])->toBe('pgsql')
        ->and($file->manifest['default_charset'])->toBe('UTF8')
        ->and($file->engine())->toBe(ConnectionDriver::Pgsql);

    $dump = collect($this->tools->pipelines)->first(fn (array $p) => str_contains($p['command'], '${:BM_DUMP}'));
    expect($dump['command'])->toContain('--format=plain')
        ->and($dump['command'])->not->toContain('Pg:Secret')
        ->and($dump['env']['BM_DUMP'])->toBe('pg_dump')
        ->and($dump['env']['PGDATABASE'])->toBe('fixflow')
        ->and($dump['env']['PGUSER'])->toBe('bm_backup')
        ->and($dump['env']['PGPORT'])->toBe('5432')
        ->and($dump['env'])->not->toHaveKey('PGPASSWORD')
        // ":" and "\" are escaped in the password file.
        ->and($dump['pgpass'])->toBe("*:*:*:*:Pg\\:Secret\\\\Pass\n")
        ->and(is_file($dump['env']['PGPASSFILE']))->toBeFalse();

    expect($file->log)->toContain('-- PostgreSQL database dump complete');
});

test('a PostgreSQL dump without the pg_dump trailer fails', function () {
    $this->tools->sqlTail = "INSERT INTO t VALUES (1);\n-- Dump completed on 2026-09-23\n";

    $file = pgBackup();

    expect($file->status)->toBe(BackupFileStatus::Failed)
        ->and($file->error)->toContain('PostgreSQL database dump complete');
});

test('a PostgreSQL restore recreates the database with its encoding and imports with psql in one transaction', function () {
    $server = FakeDatabaseServerClient::with(['fixflow' => 7]);
    $server->tableCounts['fixflow_copy'] = 7;
    app()->instance(DatabaseServerClient::class, $server);
    $content = 'AGE-ENCRYPTED:PG';
    $run = BackupRun::factory()->create(['trigger' => RunTrigger::Scheduled, 'connection_id' => $this->pg->id]);
    $file = BackupFile::factory()->create([
        'backup_run_id' => $run->id,
        'database_id' => $this->database->id,
        'filename' => 'fixflow__20260920-020000__scheduled.sql.zst.age',
        'sha256' => hash('sha256', $content),
        'manifest' => ['engine' => 'pgsql', 'table_count' => 7, 'default_charset' => 'UTF8', 'default_collation' => 'en_US.UTF-8'],
    ]);
    $path = $this->local->pathFor('apps_server/fixflow/'.$file->filename);
    $copy = BackupCopy::factory()->create(['backup_file_id' => $file->id, 'destination_id' => $this->local->id, 'remote_path' => $path]);
    $this->tools->remote['BMDEST:'.$path] = ['size' => strlen($content), 'sha256' => hash('sha256', $content), 'content' => $content];
    Queue::fake();
    actingAsRole(Role::Admin);

    $this->post('/restores', [
        'backup_file_id' => $file->id,
        'source_copy_id' => $copy->id,
        'target_connection_id' => $this->pg->id,
        'target_database' => 'fixflow_copy',
        'mode' => 'new_copy',
        'confirmation' => 'fixflow_copy',
        'age_identity' => PG_IDENTITY,
    ])->assertSessionHasNoErrors();

    $restore = RestoreJob::query()->firstOrFail();
    app()->call([new RestoreDatabaseJob($restore->id), 'handle']);

    expect($restore->fresh()->error)->toBeNull()
        ->and($restore->fresh()->status)->toBe(RestoreStatus::Success);

    $recreate = $this->tools->sqlCalls[0];
    expect($recreate[0])->toBe('psql')
        ->and($recreate)->toContain('DROP DATABASE IF EXISTS "fixflow_copy"')
        ->and($recreate)->toContain("CREATE DATABASE \"fixflow_copy\" TEMPLATE template0 ENCODING 'UTF8' LC_COLLATE 'en_US.UTF-8' LC_CTYPE 'en_US.UTF-8'")
        ->and(implode(' ', $recreate))->toContain("pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'fixflow_copy'");

    $import = collect($this->tools->pipelines)->first(fn (array $p) => str_contains($p['command'], '${:BM_AGE}'));
    expect($import['command'])->toContain('${:BM_PSQL}')
        ->and($import['command'])->toContain('--single-transaction')
        ->and($import['command'])->toContain('ON_ERROR_STOP=1')
        ->and($import['env']['PGDATABASE'])->toBe('fixflow_copy')
        ->and($this->tools->identitySeen)->toBe(PG_IDENTITY."\n");
});

test('a PostgreSQL backup cannot be restored into a MariaDB connection', function () {
    app()->instance(DatabaseServerClient::class, FakeDatabaseServerClient::with([]));
    $maria = ServerConnection::factory()->create(['name' => 'vps']);
    $run = BackupRun::factory()->create(['connection_id' => $this->pg->id]);
    $file = BackupFile::factory()->create(['backup_run_id' => $run->id, 'database_id' => $this->database->id, 'manifest' => ['engine' => 'pgsql']]);
    $copy = BackupCopy::factory()->create(['backup_file_id' => $file->id, 'destination_id' => $this->local->id]);
    actingAsRole(Role::Admin);

    $this->post('/restores', [
        'backup_file_id' => $file->id,
        'source_copy_id' => $copy->id,
        'target_connection_id' => $maria->id,
        'target_database' => 'fixflow_copy',
        'mode' => 'new_copy',
        'confirmation' => 'fixflow_copy',
        'age_identity' => PG_IDENTITY,
    ])->assertSessionHasErrors('target_connection_id');

    expect(RestoreJob::query()->count())->toBe(0);
});

test('the restore wizard only offers servers of the same engine', function () {
    ServerConnection::factory()->create(['name' => 'vps']);
    actingAsRole(Role::Admin);

    $this->get('/restores/new?database='.$this->database->id)
        ->assertInertia(fn ($page) => $page->has('connections', 1)->where('connections.0.label', 'apps-server'));
});

test('the PostgreSQL client lists databases without templates and counts tables per database', function () {
    $queries = [];
    Process::fake(function (PendingProcess $p) use (&$queries) {
        $sql = $p->command[array_search('-c', $p->command, true) + 1];
        $queries[] = [$p->environment['PGDATABASE'], $sql];

        return match (true) {
            str_contains($sql, 'FROM pg_database d') => Process::result("fixflow|8192000|UTF8|en_US.UTF-8\npostgres|7000|UTF8|en_US.UTF-8\nbad-name|1|UTF8|C\ntenant_2|4096|UTF8|C\n"),
            str_contains($sql, 'pg_class') && $p->environment['PGDATABASE'] === 'fixflow' => Process::result("7\n"),
            str_contains($sql, 'pg_class') => Process::result('', 'FATAL: permission denied for database "tenant_2"', 2),
            default => Process::result("16.4 (Debian 16.4-1)\n"),
        };
    });

    $client = app(PostgresServerClient::class);
    $databases = $client->listDatabases($this->pg);

    expect(array_map(fn ($d) => $d->name, $databases))->toBe(['fixflow', 'tenant_2'])
        ->and($databases[0]->sizeBytes)->toBe(8192000)
        ->and($databases[0]->tableCount)->toBe(7)
        ->and($databases[0]->charset)->toBe('UTF8')
        ->and($databases[0]->collation)->toBe('en_US.UTF-8')
        ->and($databases[1]->tableCount)->toBe(0)
        ->and($queries[0][0])->toBe('postgres')
        ->and($client->ping($this->pg))->toBe('PostgreSQL 16.4 (Debian 16.4-1)');
});

test('the libpq environment never contains the password', function () {
    $env = PgEnv::variables($this->pg, 'fixflow', '/tmp/pgpass');

    expect($env)->toMatchArray(['PGHOST' => '127.0.0.1', 'PGPORT' => '5432', 'PGUSER' => 'bm_backup', 'PGDATABASE' => 'fixflow', 'PGPASSFILE' => '/tmp/pgpass'])
        ->and(implode(' ', $env))->not->toContain('Secret');

    $this->pg->socket = '/var/run/postgresql';
    expect(PgEnv::variables($this->pg, 'fixflow', '/tmp/pgpass')['PGHOST'])->toBe('/var/run/postgresql');
});

// --- SSH -----------------------------------------------------------------

function sshPayload(array $overrides = []): array
{
    return [
        'name' => 'apps-remote',
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 5432,
        'username' => 'bm_backup',
        'password' => 'Pg-Pass',
        'socket' => '',
        'ssh_enabled' => true,
        'ssh_host' => '203.0.113.20',
        'ssh_port' => 2222,
        'ssh_user' => 'bmtunnel',
        'new_database_policy' => 'pending',
        'is_active' => true,
        ...$overrides,
    ];
}

test('saving an SSH connection creates a key pair and shows a restricted authorized_keys line', function () {
    Queue::fake();
    actingAsRole(Role::Admin);

    $this->post('/connections', sshPayload())->assertSessionHasNoErrors();

    // Discovery waits for the first successful test: the key is not installed yet.
    Queue::assertNotPushed(DiscoverDatabasesJob::class);

    $conn = ServerConnection::query()->where('name', 'apps-remote')->firstOrFail();
    expect($conn->ssh_private_key)->toContain('FAKE-PRIVATE-KEY')
        ->and(DB::table('connections')->where('id', $conn->id)->value('ssh_private_key'))->not->toContain('FAKE-PRIVATE-KEY')
        ->and($conn->ssh_public_key)->toStartWith('ssh-ed25519 ')
        ->and($conn->sshAuthorizedKeysLine())->toBe('restrict,port-forwarding,permitopen="127.0.0.1:5432" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakePublicKey backup-manager');

    $response = $this->get('/connections')->assertOk();
    expect($response->getContent())->not->toContain('FAKE-PRIVATE-KEY');
    $response->assertInertia(fn ($page) => $page
        ->where('connections.0.ssh_enabled', true)
        ->where('connections.0.ssh_authorized_keys_line', $conn->sshAuthorizedKeysLine())
        ->where('connections.0.ssh_host_key_fingerprints', []));

    // Saving again keeps the same key.
    $this->put("/connections/{$conn->id}", sshPayload(['password' => '']))->assertSessionHasNoErrors();
    expect($conn->fresh()->ssh_public_key)->toBe($conn->ssh_public_key);
});

test('SSH connections need a host and user and cannot use a socket', function () {
    actingAsRole(Role::Admin);

    $this->post('/connections', sshPayload(['ssh_host' => '', 'ssh_user' => '', 'socket' => '/var/run/postgresql']))
        ->assertSessionHasErrors(['ssh_host', 'ssh_user', 'socket']);
});

test('the first test pins the SSH host key and a changed SSH host forgets it', function () {
    app()->instance(DatabaseServerClient::class, FakeDatabaseServerClient::with([]));
    Queue::fake();
    actingAsRole(Role::Admin);
    $this->post('/connections', sshPayload())->assertSessionHasNoErrors();
    $conn = ServerConnection::query()->where('name', 'apps-remote')->firstOrFail();

    $this->post("/connections/{$conn->id}/test")->assertSessionHas('success');

    Queue::assertPushed(DiscoverDatabasesJob::class, 1);
    $conn->refresh();
    expect($conn->ssh_host_key)->toBe(FakeBackupTools::HOST_KEY_LINE."\n")
        ->and($conn->last_test_message)->toContain('Pinned SSH host key ssh-ed25519 SHA256:');

    $keyscans = fn () => array_values(array_filter($this->tools->commands, fn (array $c) => $c[0] === 'ssh-keyscan'));
    expect($keyscans())->toBe([['ssh-keyscan', '-T', '10', '-p', '2222', '-t', 'ed25519,ecdsa,rsa', '--', '203.0.113.20']]);

    // A second test keeps the pinned key.
    $this->post("/connections/{$conn->id}/test");
    expect($keyscans())->toHaveCount(1);

    $this->put("/connections/{$conn->id}", sshPayload(['password' => '', 'ssh_host' => '203.0.113.21']))->assertSessionHasNoErrors();
    expect($conn->fresh()->ssh_host_key)->toBeNull();
});

test('host key fingerprints use the ssh-keygen format', function () {
    $fingerprints = SshHostKeys::fingerprints(FakeBackupTools::HOST_KEY_LINE);

    $blob = base64_decode('AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl');
    expect($fingerprints)->toBe(['ssh-ed25519 SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=')]);
});

test('the tunnel command pins the host key, ignores user config and only forwards to the database', function () {
    $conn = ServerConnection::factory()->make([
        'host' => '127.0.0.1', 'port' => 5432,
        'ssh_enabled' => true, 'ssh_host' => '203.0.113.20', 'ssh_port' => 2222, 'ssh_user' => 'bmtunnel',
    ]);

    $command = app(SshTunnel::class)->command($conn, 40123, '/tmp/key', '/tmp/kh');

    expect($command)->toContain('StrictHostKeyChecking=yes')
        ->and($command)->toContain('UserKnownHostsFile=/tmp/kh')
        ->and($command)->toContain('BatchMode=yes')
        ->and($command)->toContain('ExitOnForwardFailure=yes')
        ->and(array_slice($command, 1, 4))->toBe(['-N', '-T', '-F', '/dev/null'])
        ->and($command)->toContain('127.0.0.1:40123:127.0.0.1:5432')
        ->and(array_slice($command, -6))->toBe(['-p', '2222', '-l', 'bmtunnel', '--', '203.0.113.20']);
});

test('connections without SSH pass through unchanged and SSH needs a pinned host key', function () {
    $plain = ServerConnection::factory()->make();
    expect(app(SshTunnel::class)->with($plain, fn (ServerConnection $c) => $c))->toBe($plain);

    $ssh = ServerConnection::factory()->make(['ssh_enabled' => true, 'ssh_host' => '203.0.113.20', 'ssh_user' => 'bmtunnel']);
    app(SshTunnel::class)->with($ssh, fn () => null);
})->throws(BackupException::class, 'not pinned yet');

test('the tunnel hands the work a local endpoint and removes the key files afterwards', function () {
    $commands = [];
    Process::fake(function (PendingProcess $p) use (&$commands) {
        $commands[] = $p->command;

        return Process::describe()->runsFor(iterations: 100);
    });
    $tunnel = new class extends SshTunnel
    {
        protected function waitUntilListening(InvokedProcess $process, int $port): bool
        {
            return true;
        }
    };
    $conn = ServerConnection::factory()->create([
        'driver' => ConnectionDriver::Pgsql, 'host' => '127.0.0.1', 'port' => 5432,
        'ssh_enabled' => true, 'ssh_host' => '203.0.113.20', 'ssh_port' => 22, 'ssh_user' => 'bmtunnel',
        'ssh_host_key' => FakeBackupTools::HOST_KEY_LINE."\n",
    ]);
    $conn->forceFill(['ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nX\n-----END OPENSSH PRIVATE KEY-----"])->save();

    $seen = $tunnel->with($conn, function (ServerConnection $local) use (&$commands) {
        $command = $commands[0];
        $keyFile = $command[array_search('-i', $command, true) + 1];

        return ['host' => $local->host, 'port' => $local->port, 'ssh' => $local->usesSsh(), 'key' => (string) file_get_contents($keyFile), 'keyFile' => $keyFile];
    });

    expect($seen['host'])->toBe('127.0.0.1')
        ->and($seen['port'])->toBeGreaterThan(1024)
        ->and($seen['port'])->not->toBe(5432)
        ->and($seen['ssh'])->toBeFalse()
        ->and($seen['key'])->toContain('OPENSSH PRIVATE KEY')
        ->and(is_file($seen['keyFile']))->toBeFalse()
        ->and($conn->fresh()->host)->toBe('127.0.0.1')
        ->and($conn->fresh()->port)->toBe(5432);
});

test('the tool check lists the PostgreSQL and SSH tools as optional', function () {
    Process::fake(['*' => Process::result('', 'not found', 127)]);

    $tools = collect(app(ToolRegistry::class)->check())->keyBy('tool');

    expect($tools['pg_dump']['optional'])->toBeTrue()
        ->and($tools['psql']['optional'])->toBeTrue()
        ->and($tools['ssh']['optional'])->toBeTrue()
        ->and($tools['mariadb_dump']['optional'])->toBeFalse();
});

test('the wizard uses the backup engine for catalog imports under the placeholder connection', function () {
    $placeholder = ServerConnection::factory()->create(['name' => 'Imported (unassigned)', 'is_active' => false]);
    $imported = Database::factory()->create(['connection_id' => $placeholder->id, 'name' => 'fixflow']);
    $run = BackupRun::factory()->create(['connection_id' => $placeholder->id]);
    BackupFile::factory()->create(['backup_run_id' => $run->id, 'database_id' => $imported->id, 'manifest' => ['engine' => 'pgsql']]);
    ServerConnection::factory()->create(['name' => 'vps']);
    actingAsRole(Role::Admin);

    $this->get('/restores/new?database='.$imported->id)
        ->assertInertia(fn ($page) => $page->has('connections', 1)->where('connections.0.label', 'apps-server'));
});

test('a failing ssh-keygen is shown as a form error', function () {
    Process::fake(['*' => Process::result('', 'ssh-keygen: command not found', 127)]);
    actingAsRole(Role::Admin);

    $this->post('/connections', sshPayload())->assertSessionHasErrors('ssh_host');

    expect(ServerConnection::query()->where('name', 'apps-remote')->exists())->toBeFalse();
});
