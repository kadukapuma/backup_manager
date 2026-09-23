<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Shared helpers for backed string enums exposed to the UI.
 */
trait HasOptions
{
    public function label(): string
    {
        return ucwords(str_replace(['_', '.', '-'], ' ', $this->value));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
