<?php

use App\Data\RetentionPolicy;
use App\Services\Retention\GfsRetentionCalculator;
use Carbon\CarbonImmutable;

const TZ = 'Asia/Colombo';

/**
 * @return array<string, CarbonImmutable> id => date
 */
function dailyBackups(string $until, int $days, string $time = '02:00'): array
{
    $end = CarbonImmutable::parse("{$until} {$time}", TZ);
    $out = [];
    for ($i = 0; $i < $days; $i++) {
        $d = $end->subDays($i);
        $out[$d->format('Y-m-d')] = $d;
    }

    return $out;
}

test('keeps the newest backup of each of the last N days', function () {
    $backups = dailyBackups('2026-03-20', 30);
    $now = CarbonImmutable::parse('2026-03-20 10:00', TZ);

    $keep = GfsRetentionCalculator::keep($backups, new RetentionPolicy(7, 0, 0), $now, TZ);

    sort($keep);
    expect($keep)->toBe(['2026-03-14', '2026-03-15', '2026-03-16', '2026-03-17', '2026-03-18', '2026-03-19', '2026-03-20']);
});

test('only the newest backup per day is kept for hourly backups', function () {
    $now = CarbonImmutable::parse('2026-03-20 23:30', TZ);
    $backups = [];
    foreach ([1, 5, 23] as $h) {
        $backups["20-{$h}"] = CarbonImmutable::parse("2026-03-20 {$h}:00", TZ);
        $backups["19-{$h}"] = CarbonImmutable::parse("2026-03-19 {$h}:00", TZ);
    }

    $keep = GfsRetentionCalculator::keep($backups, new RetentionPolicy(2, 0, 0), $now, TZ);

    sort($keep);
    expect($keep)->toBe(['19-23', '20-23']);
});

test('weekly and monthly keep the newest backup of each ISO week and month', function () {
    $backups = dailyBackups('2026-03-20', 120);
    $now = CarbonImmutable::parse('2026-03-20 12:00', TZ);

    $keep = GfsRetentionCalculator::keep($backups, new RetentionPolicy(0, 3, 3), $now, TZ);

    sort($keep);
    // Weeks: 2026-W12 (newest 03-20), W11 (Sun 03-15), W10 (Sun 03-08).
    // Months: March (03-20), February (02-28), January (01-31).
    expect($keep)->toBe(['2026-01-31', '2026-02-28', '2026-03-08', '2026-03-15', '2026-03-20']);
});

test('the newest backup is kept even when older than every window', function () {
    $backups = ['old' => CarbonImmutable::parse('2025-01-01 02:00', TZ), 'older' => CarbonImmutable::parse('2024-12-01 02:00', TZ)];
    $now = CarbonImmutable::parse('2026-03-20 12:00', TZ);

    expect(GfsRetentionCalculator::keep($backups, new RetentionPolicy(7, 4, 6), $now, TZ))->toBe(['old'])
        ->and(GfsRetentionCalculator::prune($backups, new RetentionPolicy(7, 4, 6), $now, TZ))->toBe(['older']);
});

test('all-zero retention still keeps the newest backup', function () {
    $backups = dailyBackups('2026-03-20', 5);
    $now = CarbonImmutable::parse('2026-03-20 12:00', TZ);

    expect(GfsRetentionCalculator::keep($backups, new RetentionPolicy(0, 0, 0), $now, TZ))->toBe(['2026-03-20']);
});

test('day boundaries use the configured timezone, not UTC', function () {
    // 2026-03-20 00:30 in Colombo is 2026-03-19 19:00 UTC.
    $backups = [
        'late' => CarbonImmutable::parse('2026-03-20 00:30', TZ)->utc(),
        'early' => CarbonImmutable::parse('2026-03-19 23:00', TZ)->utc(),
    ];
    $now = CarbonImmutable::parse('2026-03-20 12:00', TZ);

    $keep = GfsRetentionCalculator::keep($backups, new RetentionPolicy(2, 0, 0), $now, TZ);

    sort($keep);
    expect($keep)->toBe(['early', 'late']);
});

test('empty input returns nothing', function () {
    expect(GfsRetentionCalculator::keep([], new RetentionPolicy(7, 4, 6), now(), TZ))->toBe([]);
});
