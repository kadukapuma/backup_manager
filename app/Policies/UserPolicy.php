<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ManageUsers);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ManageUsers);
    }

    public function update(User $user, User $model): bool
    {
        return $this->allows($user, Permission::ManageUsers);
    }

    /**
     * Admins cannot delete themselves, so the panel always keeps one admin.
     */
    public function delete(User $user, User $model): bool
    {
        return $this->allows($user, Permission::ManageUsers) && $user->isNot($model);
    }

    public function resetTwoFactor(User $user, User $model): bool
    {
        return $this->allows($user, Permission::ManageUsers) && $user->isNot($model);
    }
}
