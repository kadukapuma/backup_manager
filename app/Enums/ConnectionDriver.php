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
            self::Pgsql => 'PostgreSQL',
        };
    }

    /**
     * Drivers that the backup pipeline can handle today.
     */
    public function isSupported(): bool
    {
        return true;
    }

    public function isPostgres(): bool
    {
        return $this === self::Pgsql;
    }

    public function defaultPort(): int
    {
        return $this === self::Pgsql ? 5432 : 3306;
    }

    /**
     * Text the dump tool writes at the very end of a complete dump.
     */
    public function dumpCompletedMarker(): string
    {
        return $this === self::Pgsql ? '-- PostgreSQL database dump complete' : '-- Dump completed';
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
