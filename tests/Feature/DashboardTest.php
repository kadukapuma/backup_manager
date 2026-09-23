<?php

use App\Enums\Role;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('users with a role can visit the dashboard', function (Role $role) {
    actingAsRole($role);

    $this->get('/dashboard')->assertOk();
})->with([Role::Admin, Role::Operator, Role::Viewer]);

test('users without a role are forbidden', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertForbidden();
});
