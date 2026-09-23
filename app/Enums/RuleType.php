<?php

declare(strict_types=1);

namespace App\Enums;

enum RuleType: string
{
    use HasOptions;

    case Include = 'include';
    case Exclude = 'exclude';
}
