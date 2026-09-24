<?php

declare(strict_types=1);

namespace App\Services\Restore;

use App\Exceptions\BackupException;
use App\Models\ServerConnection;
use App\Services\Database\DefaultsFile;
use App\Services\Database\PgEnv;
use App\Services\Database\PostgresServerClient;
use App\Services\Ssh\SshTunnel;
use App\Support\DatabaseName;
use App\Support\JobLog;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The shell side of a restore: recreate the database and stream
 * age -d | zstd -dc | mariadb (or psql) into it, through an SSH tunnel when
 * the connection uses one.
 */
class RestorePipeline
{
    private const IDENTIFIER = '/^[A-Za-z0-9_]{1,64}$/';

    /** PostgreSQL locale names such as en_US.UTF-8, C, C.UTF-8. */
    private const PG_LOCALE = '/^[A-Za-z0-9_.@\-]{1,64}$/';

    public function __construct(private readonly SshTunnel $tunnel) {}

    /**
     * Drops the database if it exists and creates it empty with the original
     * charset/collation (MariaDB) or encoding/locale (PostgreSQL). Null values
     * fall back to the server defaults.
     */
    public function recreateDatabase(ServerConnection $connection, string $database, ?string $charset, ?string $collation, JobLog $log): void
    {
        DatabaseName::assertBackupable($database);

        $this->tunnel->with($connection, function (ServerConnection $connection) use ($database, $charset, $collation, $log): void {
            if ($connection->driver->isPostgres()) {
                $this->recreatePostgres($connection, $database, $charset, $collation);
            } else {
                $this->recreateMariadb($connection, $database, $charset ?? 'utf8mb4', $collation ?? 'utf8mb4_unicode_ci');
            }
            $log->add("Recreated database {$database}.");
        });
    }

    /**
     * age -d -i IDENTITY FILE | zstd -dc | mariadb DB   (or psql, in one transaction)
     */
    public function import(ServerConnection $connection, string $database, string $encryptedPath, string $identityPath, JobLog $log): void
    {
        DatabaseName::assertBackupable($database);

        $this->tunnel->with($connection, function (ServerConnection $connection) use ($database, $encryptedPath, $identityPath, $log): void {
            $env = [
                'BM_AGE' => $this->bin('age'),
                'BM_ZSTD' => $this->bin('zstd'),
                'BM_ID' => $identityPath,
                'BM_IN' => $encryptedPath,
                'BM_DB' => $database,
            ];

            if ($connection->driver->isPostgres()) {
                // ON_ERROR_STOP + one transaction: a failed statement rolls back the whole import.
                $script = 'set -o pipefail; '
                    .'"${:BM_AGE}" -d -i "${:BM_ID}" "${:BM_IN}" '
                    .'| "${:BM_ZSTD}" -dc -q '
                    .'| "${:BM_PSQL}" -X -q -w -v ON_ERROR_STOP=1 --single-transaction -o /dev/null';

                PgEnv::with($connection, $database, fn (array $pgEnv) => $this->runImport($script, [...$pgEnv, ...$env, 'BM_PSQL' => $this->bin('psql')], $log));

                return;
            }

            $script = 'set -o pipefail; '
                .'"${:BM_AGE}" -d -i "${:BM_ID}" "${:BM_IN}" '
                .'| "${:BM_ZSTD}" -dc -q '
                .'| "${:BM_MARIADB}" --defaults-extra-file="${:BM_CNF}" --default-character-set=utf8mb4 "${:BM_DB}"';

            DefaultsFile::with($connection, fn (string $cnf) => $this->runImport($script, [...$env, 'BM_MARIADB' => $this->bin('mariadb'), 'BM_CNF' => $cnf], $log));
        });
    }

    private function recreateMariadb(ServerConnection $connection, string $database, string $charset, string $collation): void
    {
        $quoted = DatabaseName::quoted($database);
        if (preg_match(self::IDENTIFIER, $charset) !== 1 || preg_match(self::IDENTIFIER, $collation) !== 1) {
            throw new BackupException('Invalid charset or collation in the manifest.');
        }

        $sql = "DROP DATABASE IF EXISTS {$quoted}; CREATE DATABASE {$quoted} CHARACTER SET {$charset} COLLATE {$collation};";

        DefaultsFile::with($connection, function (string $cnf) use ($sql): void {
            $result = Process::timeout(600)->run([$this->bin('mariadb'), '--defaults-extra-file='.$cnf, '-e', $sql]);

            if (! $result->successful()) {
                throw new BackupException('Could not recreate the database: '.Str::limit(trim($result->errorOutput()), 800));
            }
        });
    }

    /**
     * Each statement is a separate -c so DROP/CREATE DATABASE run outside a
     * transaction. Open sessions on the database are ended first, otherwise
     * PostgreSQL refuses to drop it.
     */
    private function recreatePostgres(ServerConnection $connection, string $database, ?string $encoding, ?string $locale): void
    {
        $quoted = DatabaseName::pgQuoted($database);

        $create = "CREATE DATABASE {$quoted} TEMPLATE template0";
        if ($encoding !== null && $encoding !== '') {
            if (preg_match(self::IDENTIFIER, $encoding) !== 1) {
                throw new BackupException('Invalid encoding in the manifest.');
            }
            $create .= " ENCODING '{$encoding}'";
        }
        if ($locale !== null && $locale !== '') {
            if (preg_match(self::PG_LOCALE, $locale) !== 1) {
                throw new BackupException('Invalid locale in the manifest.');
            }
            $create .= " LC_COLLATE '{$locale}' LC_CTYPE '{$locale}'";
        }

        PgEnv::with($connection, PostgresServerClient::MAINTENANCE_DATABASE, function (array $env) use ($database, $quoted, $create): void {
            $result = Process::timeout(600)->env($env)->run([
                $this->bin('psql'), '-X', '-q', '-w', '-v', 'ON_ERROR_STOP=1', '-o', '/dev/null',
                '-c', "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$database}' AND pid <> pg_backend_pid()",
                '-c', "DROP DATABASE IF EXISTS {$quoted}",
                '-c', $create,
            ]);

            if (! $result->successful()) {
                throw new BackupException('Could not recreate the database: '.Str::limit(trim($result->errorOutput()), 800));
            }
        });
    }

    /**
     * @param  array<string, string>  $env
     */
    private function runImport(string $script, array $env, JobLog $log): void
    {
        $result = Process::timeout((int) config('backup-manager.timeouts.restore'))->env($env)->run($script);

        $stderr = trim($result->errorOutput());
        if ($stderr !== '') {
            $log->add('import: '.Str::limit($stderr, 2000));
        }

        if (! $result->successful()) {
            $hint = str_contains($stderr, 'no identity matched') ? ' (the age private key does not match this backup)' : '';

            throw new BackupException('Import failed'.$hint.': '.Str::limit($stderr !== '' ? $stderr : 'exit '.$result->exitCode(), 800));
        }
    }

    private function bin(string $tool): string
    {
        return (string) config("backup-manager.binaries.{$tool}");
    }
}
