<?php

declare(strict_types=1);

namespace App\Enums;

enum TestStatus: string
{
    use HasOptions;

    case Ok = 'ok';
    case Running = 'running';
    case Failed = 'failed';
}
