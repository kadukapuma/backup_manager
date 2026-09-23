<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\SelectionRule;
use App\Models\User;

class SelectionRulePolicy
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

    public function update(User $user, SelectionRule $selectionRule): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }

    public function delete(User $user, SelectionRule $selectionRule): bool
    {
        return $this->allows($user, Permission::ManageConfig);
    }
}
