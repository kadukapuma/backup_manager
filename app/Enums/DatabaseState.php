<?php

declare(strict_types=1);

namespace App\Enums;

enum DatabaseState: string
{
    use HasOptions;

    case Included = 'included';
    case Excluded = 'excluded';
    case Pending = 'pending';
}
