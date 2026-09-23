<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Destination;
use App\Models\User;

class DestinationPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ViewPanel);
    }

    public function view(User $user, Destination $destination): bool
    {
        return $this->allows($user, Permission::ViewPanel);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }

    public function update(User $user, Destination $destination): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }

    public function delete(User $user, Destination $destination): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }

    public function test(User $user, Destination $destination): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }

    public function rebuildCatalog(User $user, Destination $destination): bool
    {
        return $this->allows($user, Permission::ManageSettings);
    }
}
