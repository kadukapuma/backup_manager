<?php

declare(strict_types=1);

namespace App\Enums;

enum NewDatabasePolicy: string
{
    use HasOptions;

    case AutoInclude = 'auto_include';
    case Pending = 'pending';
}
