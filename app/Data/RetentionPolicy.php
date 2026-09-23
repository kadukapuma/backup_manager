<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Grandfather-father-son retention counts.
 */
final class RetentionPolicy
{
    public function __construct(
        public readonly int $keepDaily,
        public readonly int $keepWeekly,
        public readonly int $keepMonthly,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        return new self(
            max(0, (int) ($data['keep_daily'] ?? 7)),
            max(0, (int) ($data['keep_weekly'] ?? 4)),
            max(0, (int) ($data['keep_monthly'] ?? 6)),
        );
    }

    /**
     * @return array{keep_daily: int, keep_weekly: int, keep_monthly: int}
     */
    public function toArray(): array
    {
        return [
            'keep_daily' => $this->keepDaily,
            'keep_weekly' => $this->keepWeekly,
            'keep_monthly' => $this->keepMonthly,
        ];
    }
}
