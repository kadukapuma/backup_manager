<?php

declare(strict_types=1);

namespace App\Services\Retention;

use App\Data\RetentionPolicy;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Grandfather-father-son retention, as a pure function.
 *
 * Counted back from "now" in the given timezone:
 *  - daily:   the newest backup of each of the last N calendar days
 *  - weekly:  the newest backup of each of the last N ISO weeks
 *  - monthly: the newest backup of each of the last N calendar months
 * The newest backup overall is always kept, even when it is older than every
 * window, so a database never ends up with zero backups.
 */
final class GfsRetentionCalculator
{
    /**
     * @param  array<int|string, DateTimeInterface>  $backups  id => created_at
     * @return list<int|string> ids to keep
     */
    public static function keep(array $backups, RetentionPolicy $policy, DateTimeInterface $now, string $timezone): array
    {
        if ($backups === []) {
            return [];
        }

        /** @var array<int|string, CarbonImmutable> $local */
        $local = array_map(fn (DateTimeInterface $d): CarbonImmutable => CarbonImmutable::instance($d)->setTimezone($timezone), $backups);
        arsort($local); // newest first

        $nowLocal = CarbonImmutable::instance($now)->setTimezone($timezone);
        $keep = [array_key_first($local) => true];

        $buckets = [
            [$policy->keepDaily, fn (CarbonImmutable $d): string => $d->format('Y-m-d'), fn (int $i): string => $nowLocal->subDays($i)->format('Y-m-d')],
            [$policy->keepWeekly, fn (CarbonImmutable $d): string => $d->format('o-W'), fn (int $i): string => $nowLocal->subWeeks($i)->format('o-W')],
            [$policy->keepMonthly, fn (CarbonImmutable $d): string => $d->format('Y-m'), fn (int $i): string => $nowLocal->startOfMonth()->subMonths($i)->format('Y-m')],
        ];

        foreach ($buckets as [$count, $keyOf, $windowKey]) {
            if ($count <= 0) {
                continue;
            }

            $windows = [];
            for ($i = 0; $i < $count; $i++) {
                $windows[$windowKey($i)] = true;
            }

            $taken = [];
            foreach ($local as $id => $date) {
                $bucket = $keyOf($date);
                if (isset($windows[$bucket]) && ! isset($taken[$bucket])) {
                    $taken[$bucket] = true;
                    $keep[$id] = true;
                }
            }
        }

        return array_keys($keep);
    }

    /**
     * @param  array<int|string, DateTimeInterface>  $backups
     * @return list<int|string> ids to delete
     */
    public static function prune(array $backups, RetentionPolicy $policy, DateTimeInterface $now, string $timezone): array
    {
        $keep = array_flip(self::keep($backups, $policy, $now, $timezone));

        return array_values(array_filter(array_keys($backups), fn (int|string $id): bool => ! isset($keep[$id])));
    }
}
