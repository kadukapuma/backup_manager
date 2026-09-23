<?php

use App\Support\DatabaseName;

test('valid database names are accepted', function (string $name) {
    expect(DatabaseName::isBackupable($name))->toBeTrue();
})->with(['fixflow_company_12', 'kreethya', 'A_1', str_repeat('a', 64)]);

test('invalid database names are rejected', function (string $name) {
    expect(DatabaseName::isValid($name))->toBeFalse();
})->with([
    'empty' => '',
    'too long' => str_repeat('a', 65),
    'dash' => 'my-db',
    'space' => 'my db',
    'backtick' => 'db`; DROP',
    'shell' => 'db$(rm -rf /)',
    'quote' => "db'x",
    'newline' => "db\nx",
    'dot' => 'db.x',
    'unicode' => 'dbé',
]);

test('system schemas are never backupable', function (string $name) {
    expect(DatabaseName::isValid($name))->toBeTrue()
        ->and(DatabaseName::isBackupable($name))->toBeFalse();
})->with(['information_schema', 'performance_schema', 'mysql', 'sys', 'MYSQL']);

test('quoted rejects invalid names', function () {
    expect(DatabaseName::quoted('shop'))->toBe('`shop`');
    DatabaseName::quoted('bad`name');
})->throws(InvalidArgumentException::class);
