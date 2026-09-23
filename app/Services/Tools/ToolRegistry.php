<?php

declare(strict_types=1);

namespace App\Services\Tools;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Resolves configured binary paths and reports installed versions.
 */
class ToolRegistry
{
    /**
     * Version flag per tool.
     */
    private const VERSION_ARGS = [
        'bash' => ['--version'],
        'mariadb_dump' => ['--version'],
        'mariadb' => ['--version'],
        'zstd' => ['--version'],
        'age' => ['--version'],
        'rclone' => ['version'],
        'sha256sum' => ['--version'],
    ];

    public function path(string $tool): string
    {
        return (string) config("backup-manager.binaries.{$tool}", $tool);
    }

    /**
     * @return list<array{tool: string, path: string, found: bool, version: string|null, error: string|null}>
     */
    public function check(): array
    {
        $results = [];
        foreach (array_keys(self::VERSION_ARGS) as $tool) {
            $results[] = $this->checkTool($tool);
        }

        $results[] = $this->checkPipefail();

        return $results;
    }

    /**
     * Versions of all tools, for backup manifests.
     *
     * @return array<string, string|null>
     */
    public function versions(): array
    {
        $versions = [];
        foreach (['mariadb_dump', 'zstd', 'age', 'rclone'] as $tool) {
            $versions[$tool] = $this->checkTool($tool)['version'];
        }

        return $versions;
    }

    /**
     * @return array{tool: string, path: string, found: bool, version: string|null, error: string|null}
     */
    public function checkTool(string $tool): array
    {
        $path = $this->path($tool);

        try {
            $result = Process::timeout(15)->run([$path, ...self::VERSION_ARGS[$tool]]);
        } catch (Throwable $e) {
            return ['tool' => $tool, 'path' => $path, 'found' => false, 'version' => null, 'error' => $e->getMessage()];
        }

        $output = trim($result->output()."\n".$result->errorOutput());
        $firstLine = trim(strtok($output, "\n") ?: '');

        return [
            'tool' => $tool,
            'path' => $path,
            'found' => $result->successful(),
            'version' => $result->successful() ? $firstLine : null,
            'error' => $result->successful() ? null : ($firstLine !== '' ? $firstLine : 'Exit code '.$result->exitCode()),
        ];
    }

    /**
     * Pipelines run through /bin/sh with "set -o pipefail"; the shell must support it.
     *
     * @return array{tool: string, path: string, found: bool, version: string|null, error: string|null}
     */
    private function checkPipefail(): array
    {
        try {
            $result = Process::timeout(10)->run('set -o pipefail && false | true');
            $ok = $result->exitCode() === 1;
        } catch (Throwable $e) {
            return ['tool' => 'sh pipefail', 'path' => '/bin/sh', 'found' => false, 'version' => null, 'error' => $e->getMessage()];
        }

        return [
            'tool' => 'sh pipefail',
            'path' => '/bin/sh',
            'found' => $ok,
            'version' => $ok ? 'supported' : null,
            'error' => $ok ? null : '/bin/sh does not support "set -o pipefail" (use bash as /bin/sh).',
        ];
    }
}
