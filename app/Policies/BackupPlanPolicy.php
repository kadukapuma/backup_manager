<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\BackupPlan;
use App\Models\User;

class BackupPlanPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ViewPanel);
    }

    public function view(User $user, BackupPlan $backupPlan): bool
    {
        return $this->allows($user, Permission::ViewPanel);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }

    public function update(User $user, BackupPlan $backupPlan): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }

    public function delete(User $user, BackupPlan $backupPlan): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }

    public function run(User $user, BackupPlan $backupPlan): bool
    {
        return $this->allows($user, Permission::RunBackups);
    }
}
