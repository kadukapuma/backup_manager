<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationEvent: string
{
    use HasOptions;

    case RunFailed = 'run.failed';
    case RunPartial = 'run.partial';
    case RunSuccess = 'run.success';
    case DatabasePending = 'database.pending';
    case DatabaseMissing = 'database.missing';
    case BackupStale = 'backup.stale';
    case RestoreSuccess = 'restore.success';
    case RestoreFailed = 'restore.failed';

    public function label(): string
    {
        return match ($this) {
            self::RunFailed => 'Backup run failed',
            self::RunPartial => 'Backup run partially failed',
            self::RunSuccess => 'Backup run succeeded',
            self::DatabasePending => 'New database waiting for approval',
            self::DatabaseMissing => 'Database disappeared from server',
            self::BackupStale => 'Database has no recent backup',
            self::RestoreSuccess => 'Restore finished',
            self::RestoreFailed => 'Restore failed',
        };
    }

    /**
     * Events enabled by default on a new channel.
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return [
            self::RunFailed->value,
            self::RunPartial->value,
            self::DatabasePending->value,
            self::DatabaseMissing->value,
            self::BackupStale->value,
            self::RestoreSuccess->value,
            self::RestoreFailed->value,
        ];
    }
}
