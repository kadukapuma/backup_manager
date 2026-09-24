<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Data\BackupArtifact;
use App\Enums\VerificationLevel;
use App\Enums\VerificationStatus;
use App\Exceptions\BackupException;
use App\Models\BackupFile;
use App\Models\VerificationRun;
use App\Services\Settings\SettingsStore;
use App\Services\Tools\ToolRegistry;
use App\Support\BackupFilename;
use App\Support\JobLog;
use App\Support\WorkDir;
use Illuminate\Support\Facades\Cache;

/**
 * Produces an encrypted, verified backup file plus manifest in staging.
 */
class BackupCreator
{
    public function __construct(
        private readonly DumpPipeline $pipeline,
        private readonly SettingsStore $settings,
        private readonly ToolRegistry $tools,
    ) {}

    public function create(BackupFile $file, JobLog $log): BackupArtifact
    {
        $recipient = $this->settings->agePublicKey()
            ?? throw new BackupException('No age public key is configured (System settings). Backups are never stored unencrypted.');

        $database = $file->database;
        $connection = $database->serverConnection;
        $trigger = $file->run->trigger;
        $createdAt = now();
        $started = hrtime(true);

        $filename = BackupFilename::make($database->name, $createdAt, $trigger);
        $finalPath = WorkDir::staging().DIRECTORY_SEPARATOR.$filename;
        $manifestPath = $finalPath.BackupFilename::MANIFEST_SUFFIX;
        $compressed = WorkDir::tempPath('dump-'.$database->name, '.sql.zst');

        $log->add("Dumping {$database->name} from {$connection->name}");

        try {
            $this->pipeline->dumpCompressed($connection, $database->name, $compressed, $log);
            $log->add('Dump written ('.$this->size($compressed).' bytes compressed). Verifying…');

            $checks = $this->pipeline->verifyCompressedDump($compressed, $connection->driver);
            $log->add('Level-1 check passed: zstd frame intact, "'.$connection->driver->dumpCompletedMarker().'" present.');

            $this->pipeline->encrypt($compressed, $finalPath, $recipient);
            $log->add('Encrypted with age.');
        } finally {
            WorkDir::delete($compressed);
        }

        $size = $this->size($finalPath);
        $sha256 = (string) hash_file('sha256', $finalPath);
        $md5 = (string) hash_file('md5', $finalPath);

        $manifest = ManifestBuilder::build(
            database: $database,
            connectionName: $connection->name,
            engine: $connection->driver->value,
            filename: $filename,
            createdAt: $createdAt,
            trigger: $trigger,
            sizeBytes: $size,
            sha256: $sha256,
            md5: $md5,
            toolVersions: Cache::remember('bm:tool-versions', 600, fn (): array => $this->tools->versions()),
            ageRecipient: $recipient,
        );
        file_put_contents($manifestPath, ManifestBuilder::toJson($manifest));

        VerificationRun::query()->create([
            'backup_file_id' => $file->id,
            'level' => VerificationLevel::Checksum,
            'status' => VerificationStatus::Passed,
            'details' => [...$checks, 'sha256' => $sha256, 'size_bytes' => $size],
        ]);

        $log->add("SHA-256 {$sha256}, {$size} bytes.");

        return new BackupArtifact(
            path: $finalPath,
            manifestPath: $manifestPath,
            filename: $filename,
            sizeBytes: $size,
            sha256: $sha256,
            md5: $md5,
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
            manifest: $manifest,
        );
    }

    private function size(string $path): int
    {
        clearstatcache(true, $path);

        return (int) @filesize($path);
    }
}
