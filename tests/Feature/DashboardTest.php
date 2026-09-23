<?php

use App\Enums\Role;
use App\Enums\RunStatus;
use App\Models\BackupCopy;
use App\Models\BackupRun;
use App\Models\Database;
use App\Models\User;
use App\Services\Settings\SettingsStore;

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

test('health is red without an age key and green when everything is fine', function () {
    actingAsRole(Role::Viewer);

    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('health.ok', false));

    app(SettingsStore::class)->set(SettingsStore::AGE_PUBLIC_KEY, 'age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p');
    Database::factory()->create(['last_success_at' => now()->subHour()]);

    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('health.ok', true)->where('health.problems', []));
});

test('dashboard reports stale, pending, failed runs and storage', function () {
    app(SettingsStore::class)->set(SettingsStore::AGE_PUBLIC_KEY, 'age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p');
    Database::factory()->create(['name' => 'stale_db', 'last_success_at' => now()->subDays(3)]);
    Database::factory()->pending()->create(['name' => 'new_tenant']);
    BackupRun::factory()->create(['status' => RunStatus::Failed]);
    $copy = BackupCopy::factory()->create();
    actingAsRole(Role::Viewer);

    $this->get('/dashboard')->assertInertia(fn ($page) => $page
        ->component('dashboard')
        ->where('health.ok', false)
        ->where('staleCount', 1)
        ->where('stale.0.name', 'stale_db')
        ->where('pendingCount', 1)
        ->where('pending.0.name', 'new_tenant')
        ->has('runs', 2) // the failed run plus the run created by the copy factory
        ->where('destinations.0.copies', 1)
        ->where('destinations.0.stored_bytes', $copy->backupFile->size_bytes));
});
