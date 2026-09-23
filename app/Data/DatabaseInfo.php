<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A database as reported by the server during discovery.
 */
final class DatabaseInfo
{
    public function __construct(
        public readonly string $name,
        public readonly int $sizeBytes,
        public readonly int $tableCount,
        public readonly ?string $charset,
        public readonly ?string $collation,
    ) {}
}
