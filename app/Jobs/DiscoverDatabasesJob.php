<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TestStatus;
use App\Models\ServerConnection;
use App\Services\Discovery\DatabaseDiscoverer;
use App\Support\SecretRedactor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DiscoverDatabasesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $connectionId)
    {
        $this->onQueue((string) config('backup-manager.queues.default'));
    }

    public function uniqueId(): string
    {
        return 'discover:'.$this->connectionId;
    }

    public function handle(DatabaseDiscoverer $discoverer): void
    {
        $connection = ServerConnection::query()->find($this->connectionId);
        if ($connection === null || ! $connection->is_active) {
            return;
        }

        try {
            $discoverer->discover($connection);
        } catch (Throwable $e) {
            // A server that is briefly unreachable should not fill failed_jobs every 15 minutes.
            $message = Str::limit(SecretRedactor::redactString($e->getMessage(), [$connection->password]), 500);
            $connection->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => TestStatus::Failed,
                'last_test_message' => 'Discovery failed: '.$message,
            ])->save();
            Log::warning('Database discovery failed', ['connection' => $connection->id, 'error' => $message]);
        }
    }
}
