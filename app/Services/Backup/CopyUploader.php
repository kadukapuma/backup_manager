<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Data\BackupArtifact;
use App\Enums\CopyStatus;
use App\Enums\VerificationLevel;
use App\Enums\VerificationStatus;
use App\Models\BackupCopy;
use App\Models\BackupFile;
use App\Models\Destination;
use App\Models\VerificationRun;
use App\Services\Storage\RcloneClient;
use App\Support\BackupFilename;
use App\Support\JobLog;
use App\Support\WorkDir;
use Illuminate\Support\Str;
use Throwable;

/**
 * Uploads a backup + manifest to one destination and runs level-2 (remote)
 * verification.
 */
class CopyUploader
{
    public function __construct(private readonly RcloneClient $rclone) {}

    public function upload(BackupFile $file, BackupArtifact $artifact, Destination $destination, JobLog $log): BackupCopy
    {
        $directory = BackupFilename::directory($file->database->serverConnection->name, $file->database->name);
        $remote = $destination->pathFor($directory.'/'.$artifact->filename);

        $copy = BackupCopy::query()->updateOrCreate(
            ['backup_file_id' => $file->id, 'destination_id' => $destination->id],
            ['remote_path' => $remote, 'status' => CopyStatus::Pending, 'error' => null],
        );

        try {
            $log->add("Uploading to {$destination->name}…");
            $this->rclone->upload($destination, $artifact->path, $remote);
            $this->rclone->upload($destination, $artifact->manifestPath, $remote.BackupFilename::MANIFEST_SUFFIX);
            $copy->forceFill(['status' => CopyStatus::Uploaded])->save();

            $details = $this->verify($destination, $remote, $artifact);
            $verified = $details['method'] !== 'size_only';

            VerificationRun::query()->create([
                'backup_file_id' => $file->id,
                'backup_copy_id' => $copy->id,
                'level' => VerificationLevel::Remote,
                'status' => VerificationStatus::Passed,
                'details' => $details,
            ]);

            $copy->forceFill([
                'status' => $verified ? CopyStatus::Verified : CopyStatus::Uploaded,
                'verified_at' => $verified ? now() : null,
            ])->save();
            $log->add("{$destination->name}: ".($verified ? "verified by {$details['method']}" : 'uploaded, size matches (backend reports no usable hash)').'.');
        } catch (Throwable $e) {
            $message = Str::limit($e->getMessage(), 1000);
            $copy->forceFill(['status' => CopyStatus::Failed, 'error' => $message])->save();

            VerificationRun::query()->create([
                'backup_file_id' => $file->id,
                'backup_copy_id' => $copy->id,
                'level' => VerificationLevel::Remote,
                'status' => VerificationStatus::Failed,
                'details' => ['error' => $message],
            ]);
            $log->add("{$destination->name}: FAILED – {$message}");
        }

        return $copy;
    }

    /**
     * Compare the remote object with the local file: size always, then the
     * strongest hash the backend reports, or a download when configured.
     *
     * @return array{method: string, size_bytes: int, remote_hash?: string}
     */
    private function verify(Destination $destination, string $remote, BackupArtifact $artifact): array
    {
        $object = $this->rclone->stat($destination, $remote);

        if ($object === null) {
            throw new VerificationFailed('The uploaded file was not found on the destination.');
        }
        if ($object->size !== $artifact->sizeBytes) {
            throw new VerificationFailed("Size mismatch: local {$artifact->sizeBytes}, remote {$object->size}.");
        }

        if (isset($object->hashes['sha256'])) {
            if ($object->hashes['sha256'] !== $artifact->sha256) {
                throw new VerificationFailed('SHA-256 mismatch on the destination.');
            }

            return ['method' => 'sha256', 'size_bytes' => $object->size, 'remote_hash' => $object->hashes['sha256']];
        }

        if (isset($object->hashes['md5'])) {
            if ($object->hashes['md5'] !== $artifact->md5) {
                throw new VerificationFailed('MD5 mismatch on the destination.');
            }

            return ['method' => 'md5', 'size_bytes' => $object->size, 'remote_hash' => $object->hashes['md5']];
        }

        if (config('backup-manager.verify_by_download')) {
            $tmp = WorkDir::tempPath('verify', '.age');
            try {
                $this->rclone->download($destination, $remote, $tmp);
                $hash = (string) hash_file('sha256', $tmp);
            } finally {
                WorkDir::delete($tmp);
            }
            if ($hash !== $artifact->sha256) {
                throw new VerificationFailed('SHA-256 mismatch after downloading the copy.');
            }

            return ['method' => 'download_sha256', 'size_bytes' => $object->size, 'remote_hash' => $hash];
        }

        return ['method' => 'size_only', 'size_bytes' => $object->size];
    }
}
