<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Enums\RunTrigger;
use App\Models\Database;
use DateTimeInterface;

/**
 * The manifest travels next to every backup file so a fresh install can
 * rebuild its catalog, and restores can check the result.
 */
final class ManifestBuilder
{
    public const FORMAT_VERSION = 1;

    /**
     * @param  array<string, string|null>  $toolVersions
     * @return array<string, mixed>
     */
    public static function build(
        Database $database,
        string $connectionName,
        string $filename,
        DateTimeInterface $createdAt,
        RunTrigger $trigger,
        int $sizeBytes,
        string $sha256,
        string $md5,
        array $toolVersions,
        string $ageRecipient,
    ): array {
        return [
            'format_version' => self::FORMAT_VERSION,
            'filename' => $filename,
            'database' => $database->name,
            'connection' => $connectionName,
            'created_at' => $createdAt->format(DATE_ATOM),
            'trigger' => $trigger->value,
            'size_bytes' => $sizeBytes,
            'sha256' => $sha256,
            'md5' => $md5,
            'table_count' => $database->table_count,
            'source_size_bytes' => $database->size_bytes,
            'default_charset' => $database->default_charset,
            'default_collation' => $database->default_collation,
            'compression' => 'zstd',
            'encryption' => 'age',
            'age_recipient' => $ageRecipient,
            'tool_versions' => $toolVersions,
            'app_name' => config('app.name'),
            'app_version' => self::appVersion(),
        ];
    }

    public static function toJson(array $manifest): string
    {
        return (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * Git commit of the deployed app when available, otherwise "unknown".
     */
    public static function appVersion(): string
    {
        $head = base_path('.git/HEAD');
        if (is_readable($head)) {
            $ref = trim((string) file_get_contents($head));
            if (str_starts_with($ref, 'ref: ')) {
                $refFile = base_path('.git/'.substr($ref, 5));
                $ref = is_readable($refFile) ? trim((string) file_get_contents($refFile)) : '';
            }
            if (preg_match('/^[0-9a-f]{40}$/', $ref) === 1) {
                return substr($ref, 0, 12);
            }
        }

        return (string) config('backup-manager.app_version', 'unknown');
    }
}
