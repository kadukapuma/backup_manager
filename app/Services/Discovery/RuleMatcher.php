<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Models\SelectionRule;

/**
 * Glob matching for selection rules. Supports "*" (any run of characters) and
 * "?" (exactly one character); matching is case-sensitive, like MariaDB
 * database names on Linux.
 */
final class RuleMatcher
{
    public const PATTERN_RULE = '/^[A-Za-z0-9_*?]{1,64}$/';

    public static function globMatches(string $pattern, string $name): bool
    {
        $regex = '/^'.strtr(preg_quote($pattern, '/'), ['\*' => '.*', '\?' => '.']).'$/';

        return preg_match($regex, $name) === 1;
    }

    /**
     * First active rule (lowest priority number, then oldest) matching $name.
     *
     * @param  iterable<SelectionRule>  $rules
     */
    public static function firstMatch(string $name, iterable $rules): ?SelectionRule
    {
        foreach (self::sorted($rules) as $rule) {
            if ($rule->is_active && self::globMatches($rule->pattern, $name)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param  iterable<SelectionRule>  $rules
     * @return list<SelectionRule>
     */
    public static function sorted(iterable $rules): array
    {
        $list = is_array($rules) ? array_values($rules) : iterator_to_array($rules, false);
        usort($list, static fn (SelectionRule $a, SelectionRule $b): int => [$a->priority, $a->id] <=> [$b->priority, $b->id]);

        return $list;
    }
}
