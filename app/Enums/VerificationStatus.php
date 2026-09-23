<?php

declare(strict_types=1);

namespace App\Enums;

enum VerificationStatus: string
{
    use HasOptions;

    case Passed = 'passed';
    case Failed = 'failed';
}
