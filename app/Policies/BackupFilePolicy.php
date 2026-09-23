<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\BackupFile;
use App\Models\User;

class BackupFilePolicy
{
    use ChecksPermissions;

    public function view(User $user, BackupFile $backupFile): bool
    {
        return $this->allows($user, Permission::ViewPanel);
    }

    public function download(User $user, BackupFile $backupFile): bool
    {
        return $this->allows($user, Permission::Download);
    }

    public function delete(User $user, BackupFile $backupFile): bool
    {
        return $this->allows($user, Permission::DeleteBackups);
    }

    public function restore(User $user, BackupFile $backupFile): bool
    {
        return $this->allows($user, Permission::Restore);
    }
}
