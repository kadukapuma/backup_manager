<?php

use App\Support\SecretRedactor;

test('secret keys are redacted recursively', function () {
    $out = SecretRedactor::redactArray([
        'host' => 'db.local',
        'password' => 'hunter2',
        'nested' => ['secret_access_key' => 'abc', 'bucket' => 'b'],
        'empty_password' => '',
    ]);

    expect($out['host'])->toBe('db.local')
        ->and($out['password'])->toBe(SecretRedactor::MASK)
        ->and($out['nested']['secret_access_key'])->toBe(SecretRedactor::MASK)
        ->and($out['nested']['bucket'])->toBe('b')
        ->and($out['empty_password'])->toBe('');
});

test('secret values and age keys are removed from text', function () {
    $text = 'error: access denied for pass=hunter2 using AGE-SECRET-KEY-1QQQ9ABC';

    $out = SecretRedactor::redactString($text, ['hunter2']);

    expect($out)->not->toContain('hunter2')->not->toContain('AGE-SECRET-KEY-1QQQ9ABC');
});

test('mask only reveals the tail', function () {
    expect(SecretRedactor::mask('AKIAABCDEFGH1234'))->toBe('••••••••1234')
        ->and(SecretRedactor::mask(null))->toBeNull()
        ->and(SecretRedactor::mask('short'))->toBe('••••••••');
});
