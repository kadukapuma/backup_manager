<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;

test('only admins can open users and audit log', function (Role $role, int $status) {
    actingAsRole($role);

    $this->get('/users')->assertStatus($status);
    $this->get('/audit-log')->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'operator' => [Role::Operator, 403],
    'viewer' => [Role::Viewer, 403],
]);

test('admin can create a user with a role', function () {
    actingAsRole(Role::Admin);

    $this->post('/users', [
        'name' => 'Ops Person',
        'email' => 'ops@example.com',
        'role' => 'operator',
        'password' => 'a-long-password-123',
        'password_confirmation' => 'a-long-password-123',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'ops@example.com')->firstOrFail();
    expect($user->hasRole('operator'))->toBeTrue();

    $log = AuditLog::query()->where('action', AuditAction::UserCreated->value)->firstOrFail();
    expect(json_encode($log->meta))->not->toContain('a-long-password-123');
});

test('operators cannot create users', function () {
    actingAsRole(Role::Operator);

    $this->post('/users', [
        'name' => 'X', 'email' => 'x@example.com', 'role' => 'admin',
        'password' => 'a-long-password-123', 'password_confirmation' => 'a-long-password-123',
    ])->assertForbidden();
});

test('admin cannot remove their own admin role or delete themselves', function () {
    $admin = actingAsRole(Role::Admin);

    $this->put("/users/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'role' => 'viewer'])
        ->assertSessionHasErrors('role');

    $this->delete("/users/{$admin->id}")->assertForbidden();
});

test('admin can reset another users 2fa', function () {
    actingAsRole(Role::Admin);
    $other = userWithRole(Role::Operator);
    $other->forceFill(['two_factor_secret' => encrypt('X'), 'two_factor_confirmed_at' => now()])->save();

    $this->post("/users/{$other->id}/reset-two-factor")->assertRedirect();

    expect($other->fresh()->two_factor_secret)->toBeNull();
});

test('audit log can be filtered by action', function () {
    actingAsRole(Role::Admin);
    AuditLog::query()->create(['action' => AuditAction::SettingsChanged->value]);
    AuditLog::query()->create(['action' => AuditAction::BackupDeleted->value]);

    $this->get('/audit-log?action=settings.changed')
        ->assertInertia(fn ($page) => $page->component('audit/index')->has('logs.data', 1));
});
