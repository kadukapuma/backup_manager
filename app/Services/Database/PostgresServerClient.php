<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Data\DatabaseInfo;
use App\Models\ServerConnection;
use App\Support\DatabaseName;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * PostgreSQL metadata through psql, so the panel needs no pdo_pgsql
 * extension. Maintenance queries run against the "postgres" database.
 */
class PostgresServerClient implements DatabaseServerClient
{
    public const MAINTENANCE_DATABASE = 'postgres';

    private const TABLE_COUNT_SQL = "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE c.relkind IN ('r', 'p')
          AND n.nspname NOT IN ('pg_catalog', 'information_schema')
          AND n.nspname NOT LIKE 'pg\\_toast%' AND n.nspname NOT LIKE 'pg\\_temp%'";

    public function ping(ServerConnection $connection): string
    {
        return 'PostgreSQL '.trim($this->query($connection, self::MAINTENANCE_DATABASE, 'SHOW server_version')[0][0] ?? '');
    }

    public function listDatabases(ServerConnection $connection): array
    {
        $rows = $this->query($connection, self::MAINTENANCE_DATABASE, "SELECT d.datname,
                CASE WHEN has_database_privilege(d.datname, 'CONNECT') THEN pg_database_size(d.datname) ELSE 0 END,
                pg_encoding_to_char(d.encoding),
                d.datcollate
            FROM pg_database d
            WHERE NOT d.datistemplate AND d.datallowconn
            ORDER BY d.datname");

        $databases = [];
        foreach ($rows as $row) {
            $name = (string) ($row[0] ?? '');
            if (! DatabaseName::isBackupable($name)) {
                continue;
            }

            try {
                $tables = $this->tableCount($connection, $name);
            } catch (Throwable) {
                // No CONNECT right on this database: list it, the backup will report the real error.
                $tables = 0;
            }

            $databases[] = new DatabaseInfo(
                name: $name,
                sizeBytes: (int) ($row[1] ?? 0),
                tableCount: $tables,
                charset: ($row[2] ?? '') !== '' ? $row[2] : null,
                collation: ($row[3] ?? '') !== '' ? $row[3] : null,
            );
        }

        return $databases;
    }

    public function databaseExists(ServerConnection $connection, string $database): bool
    {
        if (! DatabaseName::isValid($database)) {
            return false;
        }

        $rows = $this->query($connection, self::MAINTENANCE_DATABASE, "SELECT 1 FROM pg_database WHERE datname = '{$database}'");

        return $rows !== [];
    }

    public function tableCount(ServerConnection $connection, string $database): int
    {
        DatabaseName::assertBackupable($database);

        return (int) ($this->query($connection, $database, self::TABLE_COUNT_SQL)[0][0] ?? 0);
    }

    /**
     * Unaligned, tuples-only psql output split into rows and columns.
     *
     * @return list<list<string>>
     */
    protected function query(ServerConnection $connection, string $database, string $sql): array
    {
        return PgEnv::with($connection, $database, function (array $env) use ($sql): array {
            $result = Process::timeout((int) config('backup-manager.timeouts.command', 300))
                ->env($env)
                ->run([
                    (string) config('backup-manager.binaries.psql'),
                    '-X', '-A', '-t', '-q', '-w',
                    '-v', 'ON_ERROR_STOP=1',
                    '-c', $sql,
                ]);

            if (! $result->successful()) {
                throw new RuntimeException(trim($result->errorOutput()) ?: 'psql exited with code '.$result->exitCode());
            }

            $rows = [];
            foreach (explode("\n", str_replace("\r", '', $result->output())) as $line) {
                if ($line !== '') {
                    $rows[] = explode('|', $line);
                }
            }

            return $rows;
        });
    }
}
