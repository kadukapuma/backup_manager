<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates the first admin from ADMIN_EMAIL / ADMIN_PASSWORD. An existing user
 * with that email keeps their password and is only granted the admin role.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('backup-manager.admin.email');
        $password = (string) config('backup-manager.admin.password');

        if ($email === '') {
            $this->command?->warn('ADMIN_EMAIL is empty; no admin user created.');

            return;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            if (strlen($password) < 12) {
                throw new RuntimeException('ADMIN_PASSWORD must be at least 12 characters.');
            }

            $user = User::query()->create([
                'name' => (string) config('backup-manager.admin.name'),
                'email' => $email,
                'password' => $password,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->assignRole(Role::Admin->value);
    }
}
