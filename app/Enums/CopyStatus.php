<?php

declare(strict_types=1);

namespace App\Enums;

enum CopyStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Uploaded = 'uploaded';
    case Verified = 'verified';
    case Failed = 'failed';
    case Deleted = 'deleted';
}
