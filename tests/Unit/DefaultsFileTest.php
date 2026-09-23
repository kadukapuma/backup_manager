<?php

use App\Models\ServerConnection;
use App\Services\Database\DefaultsFile;

test('defaults file quotes and escapes credentials', function () {
    $conn = new ServerConnection([
        'host' => 'db.internal',
        'port' => 3307,
        'username' => 'backup',
        'password' => 'p"a\\ss word',
    ]);

    $contents = DefaultsFile::contents($conn);

    expect($contents)->toContain("[client]\n")
        ->toContain('user="backup"')
        ->toContain('password="p\\"a\\\\ss word"')
        ->toContain('host="db.internal"')
        ->toContain('port=3307');
});

test('socket connections omit host and port', function () {
    $conn = new ServerConnection(['host' => 'x', 'port' => 3306, 'username' => 'u', 'socket' => '/run/mysqld/mysqld.sock']);

    expect(DefaultsFile::contents($conn))
        ->toContain('socket="/run/mysqld/mysqld.sock"')
        ->not->toContain('host=');
});

test('temporary file is private and deleted afterwards', function () {
    config(['backup-manager.tmp_path' => sys_get_temp_dir().'/bm-test-'.uniqid()]);
    $conn = new ServerConnection(['host' => 'h', 'port' => 3306, 'username' => 'u', 'password' => 'secret']);

    $seen = DefaultsFile::with($conn, function (string $path): string {
        expect(file_get_contents($path))->toContain('password="secret"');
        if (DIRECTORY_SEPARATOR === '/') {
            expect(fileperms($path) & 0777)->toBe(0600);
        }

        return $path;
    });

    expect(file_exists($seen))->toBeFalse();
});

test('line breaks in credentials are rejected', function () {
    DefaultsFile::contents(new ServerConnection(['host' => 'h', 'port' => 1, 'username' => "u\nhost=evil"]));
})->throws(InvalidArgumentException::class);
