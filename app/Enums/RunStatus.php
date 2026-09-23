<?php

declare(strict_types=1);

namespace App\Enums;

enum RunStatus: string
{
    use HasOptions;

    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return in_array($this, [self::Success, self::Partial, self::Failed], true);
    }
}
