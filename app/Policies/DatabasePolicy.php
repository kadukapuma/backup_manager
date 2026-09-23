<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Database;
use App\Models\User;

class DatabasePolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ViewPanel);
    }

    public function view(User $user, Database $database): bool
    {
        return $this->allows($user, Permission::ViewPanel);
    }

    public function changeState(User $user, Database $database): bool
    {
        return $this->allows($user, Permission::OperateDatabases);
    }

    public function backupNow(User $user, Database $database): bool
    {
        return $this->allows($user, Permission::RunBackups);
    }
}
