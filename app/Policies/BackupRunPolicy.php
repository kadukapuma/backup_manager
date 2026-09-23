<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\BackupRun;
use App\Models\User;

class BackupRunPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ViewPanel);
    }

    public function view(User $user, BackupRun $backupRun): bool
    {
        return $this->allows($user, Permission::ViewPanel);
    }
}
