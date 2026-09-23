<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\RestoreJob;
use App\Models\User;

class RestoreJobPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ViewPanel);
    }

    public function view(User $user, RestoreJob $restoreJob): bool
    {
        return $this->allows($user, Permission::ViewPanel);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::Restore);
    }
}
