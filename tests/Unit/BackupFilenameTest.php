<?php

use App\Enums\RunTrigger;
use App\Models\Database;
use App\Services\Backup\ManifestBuilder;
use App\Support\BackupFilename;
use Carbon\CarbonImmutable;

test('filenames follow db__timestamp__trigger in the app timezone', function () {
    config(['app.timezone' => 'Asia/Colombo']);
    $at = CarbonImmutable::parse('2026-09-22 20:30:05', 'UTC'); // 02:00:05 next day in Colombo

    expect(BackupFilename::make('fixflow_acme', $at, RunTrigger::Scheduled))
        ->toBe('fixflow_acme__20260923-020005__scheduled.sql.zst.age');
});

test('filenames round-trip through parse', function () {
    config(['app.timezone' => 'Asia/Colombo']);
    $parsed = BackupFilename::parse('/some/dir/shop__20260101-235959__pre_restore.sql.zst.age');

    expect($parsed['database'])->toBe('shop')
        ->and($parsed['trigger'])->toBe(RunTrigger::PreRestore)
        ->and($parsed['created_at']->format('Y-m-d H:i:s'))->toBe('2026-01-01 23:59:59');
});

test('unrelated files do not parse', function (string $name) {
    expect(BackupFilename::parse($name))->toBeNull();
})->with(['notes.txt', 'shop__2026__manual.sql.zst.age', 'shop__20260101-000000__weird.sql.zst.age', 'bad-name__20260101-000000__manual.sql.zst.age']);

test('invalid database names cannot produce a filename', function () {
    BackupFilename::make('../etc', now(), RunTrigger::Manual);
})->throws(InvalidArgumentException::class);

test('remote directory uses a slug of the connection name', function () {
    expect(BackupFilename::directory('VPS Main (Colombo)', 'shop'))->toBe('vps_main_colombo/shop');
});

test('manifest contains everything needed to restore and rebuild the catalog', function () {
    $db = new Database(['name' => 'shop', 'table_count' => 42, 'size_bytes' => 1000, 'default_charset' => 'utf8mb4', 'default_collation' => 'utf8mb4_unicode_ci']);
    $at = CarbonImmutable::parse('2026-09-23 02:00:00', 'Asia/Colombo');

    $m = ManifestBuilder::build($db, 'VPS', 'shop__20260923-020000__scheduled.sql.zst.age', $at, RunTrigger::Scheduled, 123, str_repeat('a', 64), str_repeat('b', 32), ['zstd' => 'v1.5'], 'age1xyz');

    expect($m)->toMatchArray([
        'format_version' => 1,
        'database' => 'shop',
        'connection' => 'VPS',
        'created_at' => '2026-09-23T02:00:00+05:30',
        'trigger' => 'scheduled',
        'size_bytes' => 123,
        'table_count' => 42,
        'default_charset' => 'utf8mb4',
        'default_collation' => 'utf8mb4_unicode_ci',
        'encryption' => 'age',
        'age_recipient' => 'age1xyz',
    ])->and($m)->toHaveKeys(['sha256', 'md5', 'tool_versions', 'app_version']);

    expect(json_decode(ManifestBuilder::toJson($m), true))->toBe($m);
});
