<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Private working directories for staging files and short-lived secrets.
 */
final class WorkDir
{
    public static function staging(): string
    {
        return self::ensure((string) config('backup-manager.staging_path'));
    }

    public static function tmp(): string
    {
        return self::ensure((string) config('backup-manager.tmp_path'));
    }

    /**
     * A unique, not-yet-existing path inside the tmp directory.
     */
    public static function tempPath(string $prefix, string $suffix = ''): string
    {
        return self::tmp().DIRECTORY_SEPARATOR.$prefix.'-'.Str::lower(Str::random(20)).$suffix;
    }

    /**
     * Write a file readable only by the current user (0600).
     */
    public static function writePrivate(string $path, string $contents): void
    {
        $previous = umask(0077);
        try {
            if (file_put_contents($path, $contents) === false) {
                throw new RuntimeException('Could not write private file.');
            }
            @chmod($path, 0600);
        } finally {
            umask($previous);
        }
    }

    public static function delete(?string $path): void
    {
        if ($path !== null && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    public static function ensure(string $path): string
    {
        if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException("Could not create directory {$path}.");
        }

        return rtrim($path, '/\\');
    }
}
