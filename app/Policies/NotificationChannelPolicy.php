<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\NotificationChannel;
use App\Models\User;

class NotificationChannelPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ViewPanel);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }

    public function update(User $user, NotificationChannel $notificationChannel): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }

    public function delete(User $user, NotificationChannel $notificationChannel): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }

    public function test(User $user, NotificationChannel $notificationChannel): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }
}
