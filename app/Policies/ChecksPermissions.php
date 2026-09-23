<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

trait ChecksPermissions
{
    protected function allows(User $user, Permission $permission): bool
    {
        return $user->checkPermissionTo($permission->value);
    }
}
