<?php

declare(strict_types=1);

namespace App\Services\Restore;

use App\Exceptions\BackupException;
use App\Models\ServerConnection;
use App\Services\Database\DefaultsFile;
use App\Support\DatabaseName;
use App\Support\JobLog;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The shell side of a restore: recreate the database and stream
 * age -d | zstd -dc | mariadb into it.
 */
class RestorePipeline
{
    private const IDENTIFIER = '/^[A-Za-z0-9_]{1,64}$/';

    /**
     * DROP DATABASE IF EXISTS + CREATE DATABASE with the original charset/collation.
     */
    public function recreateDatabase(ServerConnection $connection, string $database, string $charset, string $collation, JobLog $log): void
    {
        $quoted = DatabaseName::quoted($database);
        if (preg_match(self::IDENTIFIER, $charset) !== 1 || preg_match(self::IDENTIFIER, $collation) !== 1) {
            throw new BackupException('Invalid charset or collation in the manifest.');
        }

        $sql = "DROP DATABASE IF EXISTS {$quoted}; CREATE DATABASE {$quoted} CHARACTER SET {$charset} COLLATE {$collation};";

        DefaultsFile::with($connection, function (string $cnf) use ($sql, $log, $database): void {
            $result = Process::timeout(600)->run([$this->bin('mariadb'), '--defaults-extra-file='.$cnf, '-e', $sql]);

            if (! $result->successful()) {
                throw new BackupException('Could not recreate the database: '.Str::limit(trim($result->errorOutput()), 800));
            }
            $log->add("Recreated database {$database}.");
        });
    }

    /**
     * age -d -i IDENTITY FILE | zstd -dc | mariadb DB
     */
    public function import(ServerConnection $connection, string $database, string $encryptedPath, string $identityPath, JobLog $log): void
    {
        DatabaseName::assertBackupable($database);

        $script = 'set -o pipefail; '
            .'"${:BM_AGE}" -d -i "${:BM_ID}" "${:BM_IN}" '
            .'| "${:BM_ZSTD}" -dc -q '
            .'| "${:BM_MARIADB}" --defaults-extra-file="${:BM_CNF}" --default-character-set=utf8mb4 "${:BM_DB}"';

        DefaultsFile::with($connection, function (string $cnf) use ($script, $database, $encryptedPath, $identityPath, $log): void {
            $result = Process::timeout((int) config('backup-manager.timeouts.restore'))
                ->env([
                    'BM_AGE' => $this->bin('age'),
                    'BM_ZSTD' => $this->bin('zstd'),
                    'BM_MARIADB' => $this->bin('mariadb'),
                    'BM_ID' => $identityPath,
                    'BM_IN' => $encryptedPath,
                    'BM_CNF' => $cnf,
                    'BM_DB' => $database,
                ])
                ->run($script);

            $stderr = trim($result->errorOutput());
            if ($stderr !== '') {
                $log->add('import: '.Str::limit($stderr, 2000));
            }

            if (! $result->successful()) {
                $hint = str_contains($stderr, 'no identity matched') ? ' (the age private key does not match this backup)' : '';

                throw new BackupException('Import failed'.$hint.': '.Str::limit($stderr !== '' ? $stderr : 'exit '.$result->exitCode(), 800));
            }
        });
    }

    private function bin(string $tool): string
    {
        return (string) config("backup-manager.binaries.{$tool}");
    }
}
