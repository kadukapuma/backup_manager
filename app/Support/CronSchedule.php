<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeInterface;
use DateTimeZone;

/**
 * Cron helpers evaluated in the plan's own timezone.
 */
final class CronSchedule
{
    /**
     * @var array<string, array{label: string, expression: string}>
     */
    public const PRESETS = [
        'hourly' => ['label' => 'Every hour', 'expression' => '0 * * * *'],
        'every_6h' => ['label' => 'Every 6 hours', 'expression' => '0 */6 * * *'],
        'daily_2am' => ['label' => 'Daily at 2:00 AM', 'expression' => '0 2 * * *'],
        'weekly' => ['label' => 'Weekly, Sunday 3:00 AM', 'expression' => '0 3 * * 0'],
    ];

    public static function isValid(string $expression): bool
    {
        // Five fields only; no @reboot-style macros.
        return count(preg_split('/\s+/', trim($expression)) ?: []) === 5 && CronExpression::isValidExpression($expression);
    }

    /**
     * The first run strictly after $after.
     */
    public static function next(string $expression, string $timezone, ?DateTimeInterface $after = null): CarbonImmutable
    {
        return self::nextRuns($expression, $timezone, 1, $after)[0];
    }

    /**
     * @return list<CarbonImmutable> in the application timezone
     */
    public static function nextRuns(string $expression, string $timezone, int $count = 5, ?DateTimeInterface $after = null): array
    {
        $cron = new CronExpression($expression);
        $from = CarbonImmutable::instance($after ?? now())->setTimezone(new DateTimeZone($timezone));
        $appTz = (string) config('app.timezone');

        $runs = [];
        for ($i = 0; $i < $count; $i++) {
            $runs[] = CarbonImmutable::instance($cron->getNextRunDate($from, $i, false, $timezone))->setTimezone($appTz);
        }

        return $runs;
    }
}
