<?php

declare(strict_types=1);

namespace App\Enums;

enum RunTrigger: string
{
    use HasOptions;

    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case PreRestore = 'pre_restore';
}
