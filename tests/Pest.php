<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class))
    ->in('Feature', 'Integration');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Create a user with the given role.
 */
function userWithRole(Role|string $role = Role::Admin): User
{
    $user = User::factory()->create();
    $user->assignRole($role instanceof Role ? $role->value : $role);

    return $user;
}

function actingAsRole(Role|string $role = Role::Admin): User
{
    $user = userWithRole($role);
    test()->actingAs($user);

    return $user;
}
