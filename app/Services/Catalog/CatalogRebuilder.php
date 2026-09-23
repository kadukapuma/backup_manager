<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Data\RemoteObject;
use App\Enums\BackupFileStatus;
use App\Enums\CopyStatus;
use App\Enums\NewDatabasePolicy;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\BackupCopy;
use App\Models\BackupFile;
use App\Models\BackupRun;
use App\Models\Database;
use App\Models\Destination;
use App\Models\ServerConnection;
use App\Services\Discovery\StateResolver;
use App\Services\Storage\RcloneClient;
use App\Support\BackupFilename;
use App\Support\DatabaseName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Rebuilds backup_files / backup_copies from the manifests stored on a
 * destination, so a fresh install can list and restore old backups.
 * Idempotent: files are matched by SHA-256 and copies by destination.
 */
class CatalogRebuilder
{
    public const PLACEHOLDER_CONNECTION = 'Imported (unassigned)';

    public function __construct(private readonly RcloneClient $rclone) {}

    /**
     * @return array{manifests: int, files_created: int, copies_linked: int, skipped: int, errors: list<string>, run_id: int}
     */
    public function rebuild(Destination $destination, ?int $userId = null): array
    {
        $root = $destination->base_path;
        $objects = [];
        foreach ($this->rclone->listFiles($destination, $root) as $object) {
            $objects[$object->path] = $object;
        }

        $run = BackupRun::query()->create([
            'trigger' => RunTrigger::Manual,
            'status' => RunStatus::Running,
            'started_at' => now(),
            'triggered_by' => $userId,
            'summary' => ['catalog_rebuild' => true, 'destination' => $destination->name],
        ]);

        $stats = ['manifests' => 0, 'files_created' => 0, 'copies_linked' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($objects as $path => $object) {
            if (! str_ends_with($path, BackupFilename::MANIFEST_SUFFIX)) {
                continue;
            }
            $stats['manifests']++;

            try {
                $result = $this->importManifest($destination, $run, $path, $objects);
                $stats['files_created'] += $result['created'] ? 1 : 0;
                $stats['copies_linked'] += $result['linked'] ? 1 : 0;
            } catch (Throwable $e) {
                $stats['skipped']++;
                if (count($stats['errors']) < 20) {
                    $stats['errors'][] = basename($path).': '.Str::limit($e->getMessage(), 200);
                }
            }
        }

        $run->forceFill([
            'status' => $stats['skipped'] > 0 && $stats['copies_linked'] === 0 && $stats['manifests'] > 0 ? RunStatus::Failed
                : ($stats['skipped'] > 0 ? RunStatus::Partial : RunStatus::Success),
            'finished_at' => now(),
            'summary' => [
                ...$run->summary,
                ...$stats,
                'databases' => 0,
                'message' => "Catalog rebuild from {$destination->name}: {$stats['manifests']} manifests, {$stats['files_created']} new backups, "
                    ."{$stats['copies_linked']} copies linked, {$stats['skipped']} skipped.",
            ],
        ])->save();

        return [...$stats, 'run_id' => $run->id];
    }

    /**
     * @param  array<string, RemoteObject>  $objects
     * @return array{created: bool, linked: bool}
     */
    private function importManifest(Destination $destination, BackupRun $run, string $manifestPath, array $objects): array
    {
        /** @var array<string, mixed>|null $manifest */
        $manifest = json_decode($this->rclone->cat($destination, $manifestPath), true);
        if (! is_array($manifest)) {
            throw new CatalogException('Manifest is not valid JSON.');
        }

        $filename = (string) ($manifest['filename'] ?? '');
        $dbName = (string) ($manifest['database'] ?? '');
        $sha256 = strtolower((string) ($manifest['sha256'] ?? ''));
        $size = (int) ($manifest['size_bytes'] ?? -1);

        if (BackupFilename::parse($filename) === null || ! DatabaseName::isBackupable($dbName) || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new CatalogException('Manifest is missing required fields or has invalid values.');
        }

        $dataPath = substr($manifestPath, 0, -strlen(BackupFilename::MANIFEST_SUFFIX));
        if (basename($dataPath) !== $filename) {
            throw new CatalogException('Manifest does not match the file next to it.');
        }
        $object = $objects[$dataPath] ?? null;
        if ($object === null) {
            throw new CatalogException('The backup file for this manifest is missing.');
        }
        if ($object->size !== $size) {
            throw new CatalogException("Size mismatch (manifest {$size}, remote {$object->size}).");
        }

        $database = $this->database((string) ($manifest['connection'] ?? ''), $dbName);

        $file = BackupFile::query()->where('database_id', $database->id)->where('sha256', $sha256)->first();
        $created = false;
        if ($file === null) {
            $file = BackupFile::query()->create([
                'backup_run_id' => $run->id,
                'database_id' => $database->id,
                'filename' => $filename,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'md5' => isset($manifest['md5']) ? (string) $manifest['md5'] : null,
                'status' => BackupFileStatus::Success,
                'manifest' => $manifest,
                'log' => 'Imported by catalog rebuild from '.$destination->name.'.',
                'created_at' => $this->createdAt($manifest, $filename),
            ]);
            $created = true;

            if ($database->last_success_at === null || $database->last_success_at->lt($file->created_at)) {
                $database->forceFill(['last_success_at' => $file->created_at])->save();
            }
        }

        $existing = BackupCopy::query()->where('backup_file_id', $file->id)->where('destination_id', $destination->id)->first();
        $linked = $existing === null || $existing->status === CopyStatus::Deleted || $existing->status === CopyStatus::Failed;

        BackupCopy::query()->updateOrCreate(
            ['backup_file_id' => $file->id, 'destination_id' => $destination->id],
            [
                'remote_path' => $dataPath,
                'status' => $existing?->status === CopyStatus::Verified ? CopyStatus::Verified : CopyStatus::Uploaded,
                'deleted_at' => null,
                'error' => null,
            ],
        );

        return ['created' => $created, 'linked' => $linked];
    }

    private function database(string $connectionName, string $dbName): Database
    {
        $connection = ServerConnection::query()->where('name', $connectionName)->first()
            ?? ServerConnection::query()->firstOrCreate(
                ['name' => self::PLACEHOLDER_CONNECTION],
                [
                    'driver' => 'mariadb',
                    'host' => 'localhost',
                    'port' => 3306,
                    'username' => 'unassigned',
                    'new_database_policy' => NewDatabasePolicy::Pending,
                    'is_active' => false,
                ],
            );

        $existing = Database::query()->where('connection_id', $connection->id)->where('name', $dbName)->first();
        if ($existing !== null) {
            return $existing;
        }

        [$state, $source] = StateResolver::resolve($dbName, $connection, $connection->selectionRules()->get());

        return Database::query()->create([
            'connection_id' => $connection->id,
            'name' => $dbName,
            'state' => $state,
            'state_source' => $source,
            'first_seen_at' => now(),
            // Unknown until discovery sees it on the server.
            'missing_since' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function createdAt(array $manifest, string $filename): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse((string) $manifest['created_at'])->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return BackupFilename::parse($filename)['created_at'] ?? CarbonImmutable::now();
        }
    }
}
