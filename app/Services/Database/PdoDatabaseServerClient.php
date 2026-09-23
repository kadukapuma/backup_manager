<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Data\DatabaseInfo;
use App\Models\ServerConnection;
use App\Support\DatabaseName;
use PDO;

/**
 * MariaDB/MySQL metadata over PDO. The password is handed to the driver
 * directly and never appears on a command line.
 */
class PdoDatabaseServerClient implements DatabaseServerClient
{
    public function ping(ServerConnection $connection): string
    {
        return (string) $this->pdo($connection)->query('SELECT VERSION()')?->fetchColumn();
    }

    public function listDatabases(ServerConnection $connection): array
    {
        $pdo = $this->pdo($connection);

        $names = array_values(array_filter(
            array_map('strval', $pdo->query('SHOW DATABASES')?->fetchAll(PDO::FETCH_COLUMN) ?: []),
            static fn (string $name): bool => DatabaseName::isBackupable($name),
        ));

        $schemata = [];
        foreach ($pdo->query('SELECT SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA')?->fetchAll(PDO::FETCH_NUM) ?: [] as $row) {
            $schemata[(string) $row[0]] = [(string) $row[1], (string) $row[2]];
        }

        $stats = [];
        $sql = "SELECT TABLE_SCHEMA, SUM(CASE WHEN TABLE_TYPE = 'BASE TABLE' THEN 1 ELSE 0 END), COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0)
                FROM information_schema.TABLES GROUP BY TABLE_SCHEMA";
        foreach ($pdo->query($sql)?->fetchAll(PDO::FETCH_NUM) ?: [] as $row) {
            $stats[(string) $row[0]] = [(int) $row[1], (int) $row[2]];
        }

        return array_map(static fn (string $name): DatabaseInfo => new DatabaseInfo(
            name: $name,
            sizeBytes: $stats[$name][1] ?? 0,
            tableCount: $stats[$name][0] ?? 0,
            charset: $schemata[$name][0] ?? null,
            collation: $schemata[$name][1] ?? null,
        ), $names);
    }

    public function databaseExists(ServerConnection $connection, string $database): bool
    {
        $stmt = $this->pdo($connection)->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->execute([$database]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function tableCount(ServerConnection $connection, string $database): int
    {
        $stmt = $this->pdo($connection)->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'"
        );
        $stmt->execute([$database]);

        return (int) $stmt->fetchColumn();
    }

    protected function pdo(ServerConnection $connection): PDO
    {
        $dsn = $connection->socket !== null && $connection->socket !== ''
            ? 'mysql:unix_socket='.$connection->socket.';charset=utf8mb4'
            : 'mysql:host='.$connection->host.';port='.$connection->port.';charset=utf8mb4';

        return new PDO($dsn, $connection->username, (string) $connection->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
