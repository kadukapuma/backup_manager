<?php

declare(strict_types=1);

namespace App\Enums;

enum VerificationLevel: string
{
    use HasOptions;

    case Checksum = 'checksum';
    case Remote = 'remote';
    case TestRestore = 'test_restore';
}
