<?php

declare(strict_types=1);

namespace App\Enums;

enum ConnectionDriver: string
{
    use HasOptions;

    case Mariadb = 'mariadb';
    case Mysql = 'mysql';
    case Pgsql = 'pgsql';

    public function label(): string
    {
        return match ($this) {
            self::Mariadb => 'MariaDB',
            self::Mysql => 'MySQL',
            self::Pgsql => 'PostgreSQL (planned)',
        };
    }

    /**
     * Drivers that the backup pipeline can handle today.
     */
    public function isSupported(): bool
    {
        return $this !== self::Pgsql;
    }

    /**
     * @return list<string>
     */
    public static function supportedValues(): array
    {
        return array_values(array_map(
            static fn (self $d): string => $d->value,
            array_filter(self::cases(), static fn (self $d): bool => $d->isSupported()),
        ));
    }
}
