<?php

declare(strict_types=1);

namespace App\Enums;

enum StateSource: string
{
    use HasOptions;

    case Manual = 'manual';
    case Rule = 'rule';
    case Policy = 'policy';
}
