<?php

namespace Tests\Fakes;

use App\Data\DatabaseInfo;
use App\Models\ServerConnection;
use App\Services\Database\DatabaseServerClient;
use RuntimeException;

/**
 * In-memory stand-in for a MariaDB server.
 */
class FakeDatabaseServerClient implements DatabaseServerClient
{
    /** @var array<string, DatabaseInfo> */
    public array $databases = [];

    public bool $fail = false;

    /** @var array<string, int> table counts reported after a restore creates a database */
    public array $tableCounts = [];

    /**
     * @param  array<string, int>  $tables  name => table count
     */
    public static function with(array $tables): self
    {
        $fake = new self;
        foreach ($tables as $name => $count) {
            $fake->databases[$name] = new DatabaseInfo($name, 1024 * $count, $count, 'utf8mb4', 'utf8mb4_unicode_ci');
        }

        return $fake;
    }

    public function ping(ServerConnection $connection): string
    {
        if ($this->fail) {
            throw new RuntimeException("SQLSTATE[HY000] [1045] Access denied for user 'backup' (using password: {$connection->password})");
        }

        return '11.4.2-MariaDB';
    }

    public function listDatabases(ServerConnection $connection): array
    {
        $this->ping($connection);

        return array_values($this->databases);
    }

    public function databaseExists(ServerConnection $connection, string $database): bool
    {
        return isset($this->databases[$database]);
    }

    public function tableCount(ServerConnection $connection, string $database): int
    {
        return $this->tableCounts[$database] ?? $this->databases[$database]->tableCount ?? 0;
    }
}
