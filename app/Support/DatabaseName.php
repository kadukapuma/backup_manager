<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * The only gate through which a database name reaches a shell command or SQL
 * identifier. Allows ^[A-Za-z0-9_]{1,64}$ and rejects system schemas.
 */
final class DatabaseName
{
    public const PATTERN = '/^[A-Za-z0-9_]{1,64}$/';

    public static function isValid(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }

    public static function isSystem(string $name): bool
    {
        /** @var list<string> $system */
        $system = config('backup-manager.system_databases', ['information_schema', 'performance_schema', 'mysql', 'sys']);

        return in_array(strtolower($name), $system, true);
    }

    /**
     * Valid and not a system schema.
     */
    public static function isBackupable(string $name): bool
    {
        return self::isValid($name) && ! self::isSystem($name);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function assertBackupable(string $name): string
    {
        if (! self::isValid($name)) {
            throw new InvalidArgumentException('Invalid database name.');
        }

        if (self::isSystem($name)) {
            throw new InvalidArgumentException('System databases cannot be backed up or restored.');
        }

        return $name;
    }

    /**
     * Backtick-quoted identifier. Only called on names that passed validation.
     */
    public static function quoted(string $name): string
    {
        self::assertBackupable($name);

        return '`'.$name.'`';
    }
}
