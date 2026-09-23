<?php

use App\Enums\RuleType;
use App\Models\SelectionRule;
use App\Services\Discovery\RuleMatcher;

function rule(int $id, string $type, string $pattern, int $priority, bool $active = true): SelectionRule
{
    $rule = new SelectionRule(['type' => RuleType::from($type), 'pattern' => $pattern, 'priority' => $priority, 'is_active' => $active]);
    $rule->id = $id;

    return $rule;
}

test('glob patterns match like shell globs', function (string $pattern, string $name, bool $expected) {
    expect(RuleMatcher::globMatches($pattern, $name))->toBe($expected);
})->with([
    ['kreethya_*', 'kreethya_shop', true],
    ['kreethya_*', 'kreethya_', true],
    ['kreethya_*', 'xkreethya_shop', false],
    ['*_test', 'shop_test', true],
    ['*_test', 'shop_test2', false],
    ['db?', 'db1', true],
    ['db?', 'db12', false],
    ['*', 'anything', true],
    ['Shop', 'shop', false],
    ['a.b', 'axb', false],
]);

test('first match by priority wins', function () {
    $rules = [
        rule(1, 'include', 'fixflow_*', 100),
        rule(2, 'exclude', '*_test', 10),
    ];

    expect(RuleMatcher::firstMatch('fixflow_test', $rules)?->id)->toBe(2)
        ->and(RuleMatcher::firstMatch('fixflow_acme', $rules)?->id)->toBe(1)
        ->and(RuleMatcher::firstMatch('other', $rules))->toBeNull();
});

test('ties on priority go to the older rule and inactive rules are ignored', function () {
    $rules = [
        rule(5, 'exclude', '*', 50),
        rule(3, 'include', '*', 50),
        rule(1, 'exclude', '*', 1, active: false),
    ];

    expect(RuleMatcher::firstMatch('x', $rules)?->id)->toBe(3);
});
