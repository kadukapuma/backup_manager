<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Models\ServerConnection;
use App\Services\Ssh\SshTunnel;

/**
 * The DatabaseServerClient the app uses: picks the client for the
 * connection's driver and opens an SSH tunnel first when the connection
 * needs one.
 */
class RoutingDatabaseServerClient implements DatabaseServerClient
{
    public function __construct(
        private readonly PdoDatabaseServerClient $mysql,
        private readonly PostgresServerClient $postgres,
        private readonly SshTunnel $tunnel,
    ) {}

    public function ping(ServerConnection $connection): string
    {
        return $this->tunnel->with($connection, fn (ServerConnection $c): string => $this->client($c)->ping($c));
    }

    public function listDatabases(ServerConnection $connection): array
    {
        return $this->tunnel->with($connection, fn (ServerConnection $c): array => $this->client($c)->listDatabases($c));
    }

    public function databaseExists(ServerConnection $connection, string $database): bool
    {
        return $this->tunnel->with($connection, fn (ServerConnection $c): bool => $this->client($c)->databaseExists($c, $database));
    }

    public function tableCount(ServerConnection $connection, string $database): int
    {
        return $this->tunnel->with($connection, fn (ServerConnection $c): int => $this->client($c)->tableCount($c, $database));
    }

    private function client(ServerConnection $connection): DatabaseServerClient
    {
        return $connection->driver->isPostgres() ? $this->postgres : $this->mysql;
    }
}
