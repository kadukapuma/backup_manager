<?php

declare(strict_types=1);

namespace App\Enums;

enum DestinationType: string
{
    use HasOptions;

    case Local = 'local';
    case Sftp = 'sftp';
    case S3 = 's3';
    case GoogleDrive = 'google_drive';
    case Ftp = 'ftp';
    case OneDrive = 'onedrive';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Local disk',
            self::Sftp => 'SFTP',
            self::S3 => 'S3 compatible',
            self::GoogleDrive => 'Google Drive (planned)',
            self::Ftp => 'FTP (planned)',
            self::OneDrive => 'OneDrive (planned)',
        };
    }

    public function isAvailable(): bool
    {
        return in_array($this, [self::Local, self::Sftp, self::S3], true);
    }

    /**
     * @return list<string>
     */
    public static function availableValues(): array
    {
        return array_values(array_map(
            static fn (self $t): string => $t->value,
            array_filter(self::cases(), static fn (self $t): bool => $t->isAvailable()),
        ));
    }

    /**
     * Config keys that hold secrets and must never be sent to the browser.
     *
     * @return list<string>
     */
    public function secretKeys(): array
    {
        return match ($this) {
            self::Sftp => ['password', 'private_key', 'private_key_passphrase'],
            self::S3 => ['secret_access_key'],
            default => [],
        };
    }
}
