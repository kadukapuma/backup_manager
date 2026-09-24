<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Models\ServerConnection;
use App\Support\WorkDir;
use Closure;
use InvalidArgumentException;

/**
 * libpq environment for pg_dump and psql. The password goes into a temporary
 * 0600 password file (PGPASSFILE), never onto a command line or into the
 * environment, and the file is always deleted afterwards.
 */
final class PgEnv
{
    /**
     * @template T
     *
     * @param  Closure(array<string, string>): T  $callback  receives the environment variables
     * @return T
     */
    public static function with(ServerConnection $connection, string $database, Closure $callback): mixed
    {
        $passFile = WorkDir::tempPath('pgpass');
        try {
            WorkDir::writePrivate($passFile, self::passFileContents($connection));

            return $callback(self::variables($connection, $database, $passFile));
        } finally {
            WorkDir::delete($passFile);
        }
    }

    /**
     * @return array<string, string>
     */
    public static function variables(ServerConnection $connection, string $database, string $passFile): array
    {
        self::assertNoBreaks($connection->username);

        $socket = $connection->socket !== null && $connection->socket !== '' ? $connection->socket : null;

        return [
            // A socket path for PostgreSQL is the directory that holds .s.PGSQL.<port>.
            'PGHOST' => $socket ?? $connection->host,
            'PGPORT' => (string) (int) $connection->port,
            'PGUSER' => $connection->username,
            'PGDATABASE' => $database,
            'PGPASSFILE' => $passFile,
            'PGCONNECT_TIMEOUT' => '10',
            'PGAPPNAME' => 'backup-manager',
        ];
    }

    /**
     * One wildcard line: this file only ever serves one connection.
     */
    public static function passFileContents(ServerConnection $connection): string
    {
        $password = (string) $connection->password;
        self::assertNoBreaks($password);

        return '*:*:*:*:'.str_replace(['\\', ':'], ['\\\\', '\\:'], $password)."\n";
    }

    private static function assertNoBreaks(string $value): void
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException('Connection values cannot contain line breaks.');
        }
    }
}
