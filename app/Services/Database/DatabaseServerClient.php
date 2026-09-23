<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Data\DatabaseInfo;
use App\Models\ServerConnection;

/**
 * Read-only metadata queries against a managed database server.
 */
interface DatabaseServerClient
{
    /**
     * Connect and return the server version string.
     */
    public function ping(ServerConnection $connection): string;

    /**
     * All non-system databases with a valid name.
     *
     * @return list<DatabaseInfo>
     */
    public function listDatabases(ServerConnection $connection): array;

    public function databaseExists(ServerConnection $connection, string $database): bool;

    /**
     * Number of base tables (views excluded) in a database.
     */
    public function tableCount(ServerConnection $connection, string $database): int;
}
