<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Services\Settings\SettingsStore;
use Illuminate\Support\Facades\Process;

const VALID_AGE_KEY = 'age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p';

test('all roles can view system settings', function (Role $role) {
    actingAsRole($role);

    $this->get('/system')->assertOk()->assertInertia(fn ($page) => $page->component('system/index'));
})->with([Role::Admin, Role::Operator, Role::Viewer]);

test('admin can save the age public key and threshold', function () {
    actingAsRole(Role::Admin);

    $this->put('/system', ['age_public_key' => ' '.VALID_AGE_KEY.' ', 'stale_after_hours' => 30])
        ->assertSessionHasNoErrors();

    $store = app(SettingsStore::class);
    expect($store->agePublicKey())->toBe(VALID_AGE_KEY)
        ->and($store->staleAfterHours())->toBe(30)
        ->and(AuditLog::query()->where('action', AuditAction::SettingsChanged->value)->exists())->toBeTrue();
});

test('an invalid age key is rejected', function (string $key) {
    actingAsRole(Role::Admin);

    $this->put('/system', ['age_public_key' => $key, 'stale_after_hours' => 26])
        ->assertSessionHasErrors('age_public_key');
})->with(['AGE-SECRET-KEY-1ABC', 'age1short', 'ssh-ed25519 AAAA']);

test('operators cannot change settings', function () {
    actingAsRole(Role::Operator);

    $this->put('/system', ['age_public_key' => VALID_AGE_KEY, 'stale_after_hours' => 26])->assertForbidden();
});

test('tool check reports versions and missing binaries', function () {
    Process::fake([
        '*zstd*' => Process::result('*** Zstandard CLI (64-bit) v1.5.5'),
        '*age*' => Process::result('v1.1.1'),
        '*rclone*' => Process::result('', 'not found', 127),
        '*' => Process::result('ok 1.0'),
    ]);
    actingAsRole(Role::Admin);

    $this->get('/system')->assertInertia(fn ($page) => $page
        ->component('system/index')
        ->loadDeferredProps(fn ($reload) => $reload
            ->where('tools', fn ($tools) => collect($tools)->firstWhere('tool', 'zstd')['version'] === '*** Zstandard CLI (64-bit) v1.5.5'
                && collect($tools)->firstWhere('tool', 'rclone')['found'] === false)));
});
