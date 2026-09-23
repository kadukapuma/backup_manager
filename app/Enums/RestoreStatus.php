<?php

declare(strict_types=1);

namespace App\Enums;

enum RestoreStatus: string
{
    use HasOptions;

    case Queued = 'queued';
    case SafetyBackup = 'safety_backup';
    case Downloading = 'downloading';
    case Verifying = 'verifying';
    case Restoring = 'restoring';
    case PostCheck = 'post_check';
    case Success = 'success';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return $this === self::Success || $this === self::Failed;
    }
}
