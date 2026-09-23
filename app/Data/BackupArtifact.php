<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A finished, encrypted backup in the staging directory.
 */
final class BackupArtifact
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(
        public readonly string $path,
        public readonly string $manifestPath,
        public readonly string $filename,
        public readonly int $sizeBytes,
        public readonly string $sha256,
        public readonly string $md5,
        public readonly int $durationMs,
        public readonly array $manifest,
    ) {}
}
