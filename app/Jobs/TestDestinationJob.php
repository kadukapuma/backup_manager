<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TestStatus;
use App\Models\Destination;
use App\Services\Storage\RcloneClient;
use App\Support\WorkDir;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Writes, reads back and deletes a small file, then records free space.
 */
class TestDestinationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $destinationId)
    {
        $this->onQueue((string) config('backup-manager.queues.default'));
    }

    public function uniqueId(): string
    {
        return 'test-destination:'.$this->destinationId;
    }

    public function handle(RcloneClient $rclone): void
    {
        $destination = Destination::query()->find($this->destinationId);
        if ($destination === null) {
            return;
        }

        $token = Str::random(32);
        $local = WorkDir::tempPath('dest-test', '.txt');
        $remote = $destination->pathFor('.backup-manager-test-'.Str::lower(Str::random(8)).'.txt');
        $uploaded = false;

        try {
            WorkDir::writePrivate($local, $token);

            $rclone->upload($destination, $local, $remote);
            $uploaded = true;

            $object = $rclone->stat($destination, $remote);
            if ($object === null || $object->size !== strlen($token)) {
                throw new RuntimeException('Uploaded test file was not found or has the wrong size.');
            }

            if (trim($rclone->cat($destination, $remote)) !== $token) {
                throw new RuntimeException('Read-back content did not match what was written.');
            }

            $rclone->delete($destination, $remote);
            $uploaded = false;

            $about = $rclone->about($destination);

            $destination->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => TestStatus::Ok,
                'last_test_message' => 'Write, read and delete succeeded.',
                'free_space_bytes' => $about['free'],
            ])->save();
        } catch (Throwable $e) {
            $destination->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => TestStatus::Failed,
                'last_test_message' => Str::limit($e->getMessage(), 1000),
            ])->save();
        } finally {
            WorkDir::delete($local);
            if ($uploaded) {
                try {
                    $rclone->delete($destination, $remote);
                } catch (Throwable) {
                    // Best effort clean-up of the test file.
                }
            }
        }
    }
}
