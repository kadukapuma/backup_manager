<?php

declare(strict_types=1);

namespace App\Enums;

enum BackupFileStatus: string
{
    use HasOptions;

    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
}
