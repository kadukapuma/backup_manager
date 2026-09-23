<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ServerConnection;
use App\Models\User;

class ServerConnectionPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ViewPanel);
    }

    public function view(User $user, ServerConnection $serverConnection): bool
    {
        return $this->allows($user, Permission::ViewPanel);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }

    public function update(User $user, ServerConnection $serverConnection): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }

    public function delete(User $user, ServerConnection $serverConnection): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }

    public function test(User $user, ServerConnection $serverConnection): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }

    public function discover(User $user, ServerConnection $serverConnection): bool
    {
        return $this->allows($user, Permission::OperateDatabases);
    }
}
