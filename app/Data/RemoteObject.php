<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A file on a destination as reported by `rclone lsjson --hash`.
 */
final class RemoteObject
{
    /**
     * @param  array<string, string>  $hashes  lower-case algorithm => hex digest
     */
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly array $hashes,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromLsjson(array $row, string $prefix = ''): self
    {
        $hashes = [];
        foreach ((array) ($row['Hashes'] ?? []) as $algo => $value) {
            if (is_string($value) && $value !== '') {
                $hashes[strtolower((string) $algo)] = strtolower($value);
            }
        }

        $path = (string) ($row['Path'] ?? $row['Name'] ?? '');

        return new self(
            path: $prefix !== '' ? rtrim($prefix, '/').'/'.$path : $path,
            size: (int) ($row['Size'] ?? 0),
            hashes: $hashes,
        );
    }
}
