<?php

namespace Tests\Fakes;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * Simulates mariadb-dump, zstd, age and rclone for pipeline tests. Files that
 * the real tools would create are created so the PHP side behaves normally.
 */
class FakeBackupTools
{
    /** @var array<string, array{size: int, sha256: string}> remote spec => object */
    public array $remote = [];

    /** @var list<array{command: string|array, env: array<string, string>, cnf: string|null}> */
    public array $pipelines = [];

    public bool $dumpFails = false;

    public bool $dumpIncomplete = false;

    /** @var list<string> destination spec prefixes whose uploads fail */
    public array $failingRemotes = [];

    public bool $remoteHashMismatch = false;

    public string $sqlTail = "INSERT INTO t VALUES (1);\n-- Dump completed on 2026-09-23 02:00:01\n";

    public static function install(): self
    {
        $fake = new self;
        Process::fake(fn (PendingProcess $p) => $fake->handle($p));

        return $fake;
    }

    public function handle(PendingProcess $process): ProcessResult|FakeProcessResult
    {
        $cmd = $process->command;
        $env = $process->environment;

        if (is_string($cmd)) {
            $cnfPath = $env['BM_CNF'] ?? null;
            $this->pipelines[] = [
                'command' => $cmd,
                'env' => $env,
                'cnf' => $cnfPath !== null && is_file($cnfPath) ? (string) file_get_contents($cnfPath) : null,
            ];

            if (str_contains($cmd, '${:BM_DUMP}')) {
                if ($this->dumpFails) {
                    return Process::result('', "mariadb-dump: Got error: 1045: \"Access denied for user 'backup'@'localhost'\"", 2);
                }
                file_put_contents($env['BM_OUT'], 'ZSTD-DATA-'.$env['BM_DB']);

                return Process::result('');
            }

            if (str_contains($cmd, 'tail -c')) {
                return Process::result($this->dumpIncomplete ? "INSERT INTO t VALUES (1);\n" : $this->sqlTail);
            }

            if (str_contains($cmd, 'pipefail && false | true')) {
                return Process::result('', '', 1);
            }

            return Process::result('');
        }

        $bin = basename((string) $cmd[0]);
        $args = array_slice($cmd, 1);

        if (in_array('--version', $args, true) || ($bin === 'rclone' && ($args[0] ?? '') === 'version')) {
            return Process::result("{$bin} v1.0");
        }

        if ($bin === 'age') {
            $out = $args[array_search('-o', $args, true) + 1];
            $in = end($args);
            file_put_contents($out, 'AGE-ENCRYPTED:'.file_get_contents($in));

            return Process::result('');
        }

        if ($bin === 'zstd') {
            return Process::result('');
        }

        if ($bin === 'rclone') {
            return $this->rclone($args);
        }

        return Process::result('');
    }

    /**
     * @param  list<string>  $args
     */
    private function rclone(array $args): FakeProcessResult
    {
        $command = $args[0];

        if ($command === 'obscure') {
            return Process::result('OBSCURED');
        }

        if ($command === 'copyto') {
            [$from, $to] = [$args[1], $args[2]];
            foreach ($this->failingRemotes as $prefix) {
                if (str_starts_with($to, $prefix)) {
                    return Process::result('', 'Failed to copy: connection refused', 1);
                }
            }
            if (str_starts_with($to, 'BMDEST:')) {
                $content = (string) file_get_contents($from);
                $this->remote[$to] = ['size' => strlen($content), 'sha256' => hash('sha256', $content), 'content' => $content];
            } else {
                // download
                $object = $this->remote[$from] ?? null;
                if ($object === null) {
                    return Process::result('', 'object not found', 3);
                }
                file_put_contents($to, $object['content']);
            }

            return Process::result('');
        }

        if ($command === 'lsjson') {
            $spec = '';
            foreach (array_reverse($args) as $arg) {
                if (str_starts_with($arg, 'BMDEST:')) {
                    $spec = $arg;
                    break;
                }
            }
            $object = $this->remote[$spec] ?? null;
            if ($object === null) {
                return Process::result('', 'error: object not found', 3);
            }

            return Process::result(json_encode([
                'Path' => basename($spec),
                'Size' => $object['size'],
                'Hashes' => ['sha256' => $this->remoteHashMismatch ? str_repeat('0', 64) : $object['sha256']],
            ]));
        }

        if ($command === 'deletefile') {
            unset($this->remote[$args[1]]);

            return Process::result('');
        }

        if ($command === 'cat') {
            return Process::result($this->remote[$args[1]]['content'] ?? '');
        }

        return Process::result('');
    }
}
