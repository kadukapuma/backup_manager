<?php

declare(strict_types=1);

namespace App\Actions\Connections;

use App\Enums\AuditAction;
use App\Enums\TestStatus;
use App\Models\ServerConnection;
use App\Services\Audit\AuditLogger;
use App\Services\Database\DatabaseServerClient;
use App\Services\Ssh\SshHostKeys;
use App\Services\Tools\ToolRegistry;
use App\Support\SecretRedactor;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connects with a 5-second timeout and records the result on the connection.
 * For SSH connections the first test pins the server's host key.
 */
class TestConnection
{
    public function __construct(
        private readonly DatabaseServerClient $client,
        private readonly AuditLogger $audit,
        private readonly SshHostKeys $hostKeys,
        private readonly ToolRegistry $tools,
    ) {}

    public function handle(ServerConnection $connection): bool
    {
        $notes = [];

        try {
            if ($this->hostKeys->pinIfMissing($connection)) {
                $notes[] = 'Pinned SSH host key '.implode(', ', SshHostKeys::fingerprints($connection->ssh_host_key)).'.';
            }

            $version = $this->client->ping($connection);
            $ok = true;
            $message = 'Connected. Server version '.$version.'.';

            if ($connection->driver->isPostgres()) {
                $notes[] = $this->pgDumpNote($version);
            }
        } catch (Throwable $e) {
            $ok = false;
            $message = Str::limit(SecretRedactor::redactString($e->getMessage(), [$connection->password]), 500);
        }

        $message = trim(implode(' ', array_filter([$message, ...$notes])));

        $connection->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $ok ? TestStatus::Ok : TestStatus::Failed,
            'last_test_message' => $message,
        ])->save();

        $this->audit->log(AuditAction::ConnectionTested, $connection, ['name' => $connection->name, 'ok' => $ok]);

        return $ok;
    }

    /**
     * pg_dump refuses to dump a server with a newer major version.
     */
    private function pgDumpNote(string $serverVersion): ?string
    {
        $client = $this->tools->pgDumpMajorVersion();
        if ($client === null) {
            return 'Warning: pg_dump is not installed on the panel server, so backups will fail.';
        }

        if (preg_match('/PostgreSQL\s+(\d+)/', $serverVersion, $m) === 1 && (int) $m[1] > $client) {
            return "Warning: pg_dump {$client} is older than the server ({$m[1]}). Install PostgreSQL {$m[1]} client tools or newer.";
        }

        return null;
    }
}
