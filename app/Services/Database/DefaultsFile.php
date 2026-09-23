<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Models\ServerConnection;
use App\Support\WorkDir;
use Closure;
use InvalidArgumentException;

/**
 * Temporary MariaDB option file (--defaults-extra-file) so credentials never
 * appear on a command line. Created with 0600 and always deleted afterwards.
 */
final class DefaultsFile
{
    /**
     * Run $callback with the path of a temporary option file, deleting it in
     * a finally block.
     *
     * @template T
     *
     * @param  Closure(string): T  $callback
     * @return T
     */
    public static function with(ServerConnection $connection, Closure $callback): mixed
    {
        $path = WorkDir::tempPath('my', '.cnf');
        try {
            WorkDir::writePrivate($path, self::contents($connection));

            return $callback($path);
        } finally {
            WorkDir::delete($path);
        }
    }

    public static function contents(ServerConnection $connection): string
    {
        $lines = ['[client]'];
        $lines[] = 'user='.self::quote($connection->username);
        if ($connection->password !== null && $connection->password !== '') {
            $lines[] = 'password='.self::quote($connection->password);
        }
        if ($connection->socket !== null && $connection->socket !== '') {
            $lines[] = 'socket='.self::quote($connection->socket);
        } else {
            $lines[] = 'host='.self::quote($connection->host);
            $lines[] = 'port='.(int) $connection->port;
            $lines[] = 'protocol=TCP';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Double-quoted option value with MariaDB option-file escaping.
     */
    private static function quote(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException('Connection values cannot contain line breaks.');
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
