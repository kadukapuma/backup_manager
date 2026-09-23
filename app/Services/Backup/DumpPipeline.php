<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Exceptions\BackupException;
use App\Models\ServerConnection;
use App\Services\Database\DefaultsFile;
use App\Support\DatabaseName;
use App\Support\JobLog;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The shell side of a backup: dump | zstd, integrity checks, age encryption.
 *
 * Pipelines use Symfony's named placeholders ("${:NAME}"), whose values are
 * passed as environment variables and escaped by Symfony; nothing user
 * supplied is ever concatenated into the command string.
 */
class DumpPipeline
{
    public const DUMP_COMPLETED_MARKER = '-- Dump completed';

    /**
     * mariadb-dump DB | zstd -> $outPath (compressed, not yet encrypted).
     */
    public function dumpCompressed(ServerConnection $connection, string $database, string $outPath, JobLog $log): void
    {
        DatabaseName::assertBackupable($database);

        $script = 'set -o pipefail; '
            .'"${:BM_DUMP}" --defaults-extra-file="${:BM_CNF}" '
            .'--single-transaction --quick --routines --triggers --events --no-tablespaces '
            .'--hex-blob --default-character-set=utf8mb4 "${:BM_DB}" '
            .'| "${:BM_ZSTD}" -q -T0 -"${:BM_LEVEL}" -o "${:BM_OUT}"';

        DefaultsFile::with($connection, function (string $cnf) use ($script, $database, $outPath, $log): void {
            $result = Process::timeout($this->timeout())
                ->env([
                    'BM_DUMP' => $this->bin('mariadb_dump'),
                    'BM_ZSTD' => $this->bin('zstd'),
                    'BM_CNF' => $cnf,
                    'BM_DB' => $database,
                    'BM_LEVEL' => (string) max(1, min(19, (int) config('backup-manager.compression_level', 3))),
                    'BM_OUT' => $outPath,
                ])
                ->run($script);

            $stderr = trim($result->errorOutput());
            if ($stderr !== '') {
                $log->add('mariadb-dump/zstd: '.Str::limit($stderr, 2000));
            }

            if (! $result->successful()) {
                throw new BackupException('Dump failed (exit '.$result->exitCode().'): '.Str::limit($stderr !== '' ? $stderr : 'no error output', 1000));
            }
        });
    }

    /**
     * Level-1 verification, done before encryption because the private key is
     * not on this server: the zstd frame must be intact and the SQL must end
     * with mariadb-dump's "-- Dump completed" trailer.
     *
     * @return array{zstd_integrity: bool, dump_completed: bool}
     */
    public function verifyCompressedDump(string $path): array
    {
        $integrity = Process::timeout($this->timeout())->run([$this->bin('zstd'), '-t', '-q', $path]);
        if (! $integrity->successful()) {
            throw new BackupException('Compressed dump failed the zstd integrity test: '.Str::limit(trim($integrity->errorOutput()), 500));
        }

        $tail = Process::timeout($this->timeout())
            ->env(['BM_ZSTD' => $this->bin('zstd'), 'BM_IN' => $path])
            ->run('set -o pipefail; "${:BM_ZSTD}" -dc -q "${:BM_IN}" | tail -c 1024');

        if (! $tail->successful() || ! str_contains($tail->output(), self::DUMP_COMPLETED_MARKER)) {
            throw new BackupException('The dump is incomplete: "-- Dump completed" was not found at the end of the SQL.');
        }

        return ['zstd_integrity' => true, 'dump_completed' => true];
    }

    /**
     * age -r RECIPIENT -o OUT.part IN, then rename to OUT.
     */
    public function encrypt(string $inPath, string $outPath, string $recipient): void
    {
        $part = $outPath.'.part';

        $result = Process::timeout($this->timeout())->run([$this->bin('age'), '-r', $recipient, '-o', $part, $inPath]);

        if (! $result->successful()) {
            @unlink($part);

            throw new BackupException('age encryption failed: '.Str::limit(trim($result->errorOutput()), 500));
        }

        if (! @rename($part, $outPath)) {
            @unlink($part);

            throw new BackupException('Could not move the encrypted file into place.');
        }
    }

    private function bin(string $tool): string
    {
        return (string) config("backup-manager.binaries.{$tool}");
    }

    private function timeout(): int
    {
        return (int) config('backup-manager.timeouts.backup');
    }
}
