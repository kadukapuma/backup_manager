<?php

use App\Support\CronSchedule;
use Carbon\CarbonImmutable;

test('next runs are computed in the plan timezone', function () {
    config(['app.timezone' => 'Asia/Colombo']);
    $from = CarbonImmutable::parse('2026-03-10 10:15:00', 'Asia/Colombo');

    $runs = CronSchedule::nextRuns('0 2 * * *', 'Asia/Colombo', 3, $from);

    expect(array_map(fn ($r) => $r->format('Y-m-d H:i T'), $runs))->toBe([
        '2026-03-11 02:00 +0530',
        '2026-03-12 02:00 +0530',
        '2026-03-13 02:00 +0530',
    ]);
});

test('a plan in another timezone is converted to the app timezone', function () {
    config(['app.timezone' => 'Asia/Colombo']);
    $from = CarbonImmutable::parse('2026-03-10 00:00:00', 'UTC');

    $next = CronSchedule::next('0 2 * * *', 'UTC', $from);

    expect($next->format('Y-m-d H:i'))->toBe('2026-03-10 07:30');
});

test('next is strictly after the given time', function () {
    $from = CarbonImmutable::parse('2026-03-10 06:00:00', 'Asia/Colombo');

    expect(CronSchedule::next('0 */6 * * *', 'Asia/Colombo', $from)->format('H:i'))->toBe('12:00');
});

test('cron validation', function (string $expr, bool $valid) {
    expect(CronSchedule::isValid($expr))->toBe($valid);
})->with([
    ['0 2 * * *', true],
    ['*/15 * * * *', true],
    ['0 3 * * 0', true],
    ['@daily', false],
    ['0 2 * *', false],
    ['61 * * * *', false],
    ['0 2 * * * *', false],
]);

test('presets are valid', function () {
    foreach (CronSchedule::PRESETS as $preset) {
        expect(CronSchedule::isValid($preset['expression']))->toBeTrue();
    }
});
