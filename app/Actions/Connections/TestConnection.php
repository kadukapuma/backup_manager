<?php

declare(strict_types=1);

namespace App\Actions\Connections;

use App\Enums\AuditAction;
use App\Enums\TestStatus;
use App\Models\ServerConnection;
use App\Services\Audit\AuditLogger;
use App\Services\Database\DatabaseServerClient;
use App\Support\SecretRedactor;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connects with a 5-second timeout and records the result on the connection.
 */
class TestConnection
{
    public function __construct(
        private readonly DatabaseServerClient $client,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(ServerConnection $connection): bool
    {
        try {
            $version = $this->client->ping($connection);
            $ok = true;
            $message = 'Connected. Server version '.$version.'.';
        } catch (Throwable $e) {
            $ok = false;
            $message = Str::limit(SecretRedactor::redactString($e->getMessage(), [$connection->password]), 500);
        }

        $connection->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $ok ? TestStatus::Ok : TestStatus::Failed,
            'last_test_message' => $message,
        ])->save();

        $this->audit->log(AuditAction::ConnectionTested, $connection, ['name' => $connection->name, 'ok' => $ok]);

        return $ok;
    }
}
