<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Data\RemoteObject;
use App\Enums\DestinationType;
use App\Models\Destination;
use App\Support\SecretRedactor;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Thin wrapper around the rclone CLI. Every command is an argv array (no
 * shell) and gets its remote configuration through environment variables.
 */
class RcloneClient
{
    public function __construct(private readonly RcloneEnvBuilder $envBuilder) {}

    /**
     * "BMDEST:<path>" for a destination-relative path (as stored in backup_copies.remote_path).
     */
    public function spec(Destination $destination, string $remotePath): string
    {
        $path = $remotePath;
        if ($destination->type === DestinationType::S3) {
            $path = trim((string) ($destination->config['bucket'] ?? ''), '/').'/'.ltrim($remotePath, '/');
        }

        return RcloneEnvBuilder::REMOTE.':'.$path;
    }

    public function upload(Destination $destination, string $localPath, string $remotePath): void
    {
        $this->run($destination, ['copyto', $localPath, $this->spec($destination, $remotePath)], $this->transferTimeout());
    }

    public function download(Destination $destination, string $remotePath, string $localPath): void
    {
        $this->run($destination, ['copyto', $this->spec($destination, $remotePath), $localPath], $this->transferTimeout());
    }

    /**
     * Size and hashes of one remote file, or null if it does not exist.
     */
    public function stat(Destination $destination, string $remotePath): ?RemoteObject
    {
        $result = $this->execute($destination, ['lsjson', '--stat', '--hash', '--hash-type', 'sha256', '--hash-type', 'md5', $this->spec($destination, $remotePath)], 300);

        if (! $result->successful()) {
            if (str_contains(strtolower($result->errorOutput()), 'not found')) {
                return null;
            }
            $this->fail($destination, 'lsjson', $result);
        }

        /** @var array<string, mixed>|null $row */
        $row = json_decode($result->output(), true);

        return is_array($row) ? RemoteObject::fromLsjson($row) : null;
    }

    /**
     * Files under a directory, recursively, optionally filtered by an rclone include glob.
     *
     * @return list<RemoteObject>
     */
    public function listFiles(Destination $destination, string $remoteDir, ?string $include = null): array
    {
        $args = ['lsjson', '-R', '--files-only', '--no-mimetype'];
        if ($include !== null) {
            $args[] = '--include';
            $args[] = $include;
        }
        $args[] = $this->spec($destination, $remoteDir);

        $result = $this->execute($destination, $args, 600);
        if (! $result->successful()) {
            if (str_contains(strtolower($result->errorOutput()), 'directory not found')) {
                return [];
            }
            $this->fail($destination, 'lsjson', $result);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode($result->output(), true) ?: [];

        return array_map(fn (array $row): RemoteObject => RemoteObject::fromLsjson($row, $remoteDir), $rows);
    }

    public function cat(Destination $destination, string $remotePath): string
    {
        return $this->run($destination, ['cat', $this->spec($destination, $remotePath)], 300)->output();
    }

    public function delete(Destination $destination, string $remotePath): void
    {
        $result = $this->execute($destination, ['deletefile', $this->spec($destination, $remotePath)], 300);

        // Deleting something that is already gone is fine.
        if (! $result->successful() && ! str_contains(strtolower($result->errorOutput()), 'not found')) {
            $this->fail($destination, 'deletefile', $result);
        }
    }

    /**
     * Free/used bytes when the backend supports `rclone about`, otherwise nulls.
     *
     * @return array{free: int|null, used: int|null}
     */
    public function about(Destination $destination): array
    {
        $root = $destination->type === DestinationType::S3
            ? $this->spec($destination, '')
            : $this->spec($destination, $destination->base_path !== '' ? $destination->base_path : '/');

        $result = $this->execute($destination, ['about', '--json', $root], 60);
        if (! $result->successful()) {
            return ['free' => null, 'used' => null];
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($result->output(), true) ?: [];

        return [
            'free' => isset($data['free']) ? (int) $data['free'] : null,
            'used' => isset($data['used']) ? (int) $data['used'] : null,
        ];
    }

    /**
     * @param  list<string>  $args
     */
    protected function run(Destination $destination, array $args, int $timeout): ProcessResult
    {
        $result = $this->execute($destination, $args, $timeout);
        if (! $result->successful()) {
            $this->fail($destination, $args[0], $result);
        }

        return $result;
    }

    /**
     * @param  list<string>  $args
     */
    protected function execute(Destination $destination, array $args, int $timeout): ProcessResult
    {
        return Process::timeout($timeout)
            ->env($this->envBuilder->build($destination))
            ->run([(string) config('backup-manager.binaries.rclone'), ...$args, '--retries', '3', '--low-level-retries', '10']);
    }

    protected function fail(Destination $destination, string $command, ProcessResult $result): never
    {
        $message = SecretRedactor::redactString(trim($result->errorOutput()) ?: trim($result->output()), $this->envBuilder->secrets($destination));

        throw new RcloneException("rclone {$command} failed on {$destination->name}: ".Str::limit($message, 800));
    }

    private function transferTimeout(): int
    {
        return (int) config('backup-manager.timeouts.backup');
    }
}
