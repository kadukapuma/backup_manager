<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RunTrigger;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * {db}__{YYYYmmdd-HHMMSS}__{trigger}.sql.zst.age, stored under
 * {connection-slug}/{db}/ on every destination.
 */
final class BackupFilename
{
    public const EXTENSION = '.sql.zst.age';

    public const MANIFEST_SUFFIX = '.manifest.json';

    private const PARSE = '/^(?<db>[A-Za-z0-9_]{1,64})__(?<ts>\d{8}-\d{6})__(?<trigger>[a-z_]+)\.sql\.zst\.age$/';

    public static function make(string $database, DateTimeInterface $at, RunTrigger $trigger): string
    {
        DatabaseName::assertBackupable($database);

        return $database.'__'.CarbonImmutable::instance($at)->setTimezone((string) config('app.timezone'))->format('Ymd-His')
            .'__'.$trigger->value.self::EXTENSION;
    }

    public static function connectionSlug(string $connectionName): string
    {
        $slug = Str::slug($connectionName, '_');

        return $slug !== '' ? $slug : 'connection';
    }

    /**
     * Destination-relative directory below base_path.
     */
    public static function directory(string $connectionName, string $database): string
    {
        return self::connectionSlug($connectionName).'/'.$database;
    }

    /**
     * @return array{database: string, created_at: CarbonImmutable, trigger: RunTrigger}|null
     */
    public static function parse(string $filename): ?array
    {
        if (preg_match(self::PARSE, basename($filename), $m) !== 1) {
            return null;
        }

        $trigger = RunTrigger::tryFrom($m['trigger']);
        $createdAt = CarbonImmutable::createFromFormat('Ymd-His', $m['ts'], (string) config('app.timezone'));

        if ($trigger === null || $createdAt === false) {
            return null;
        }

        return ['database' => $m['db'], 'created_at' => $createdAt, 'trigger' => $trigger];
    }
}
