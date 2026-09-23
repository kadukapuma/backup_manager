<?php

declare(strict_types=1);

namespace App\Enums;

enum RestoreMode: string
{
    use HasOptions;

    case Replace = 'replace';
    case NewCopy = 'new_copy';
}
