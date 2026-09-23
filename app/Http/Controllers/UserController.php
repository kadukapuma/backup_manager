<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()->with('roles')->orderBy('name')->get()->map(fn (User $u): array => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->primaryRole()?->value,
            'two_factor_enabled' => $u->hasTwoFactorEnabled(),
            'created_at' => $u->created_at?->toIso8601String(),
        ]);

        return Inertia::render('users/index', [
            'users' => $users,
            'roles' => Role::options(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->syncRoles([$data['role']]);

        $this->audit->log(AuditAction::UserCreated, $user, ['email' => $user->email, 'role' => $data['role']]);

        return back()->with('success', 'User created.');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $oldRole = $user->primaryRole()?->value;

        $user->fill(['name' => $data['name'], 'email' => $data['email']]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();
        $user->syncRoles([$data['role']]);

        $this->audit->log(AuditAction::UserUpdated, $user, [
            'email' => $user->email,
            'role_from' => $oldRole,
            'role_to' => $data['role'],
            'password_changed' => ! empty($data['password']),
        ]);

        return back()->with('success', 'User updated.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $this->audit->log(AuditAction::UserDeleted, $user, ['email' => $user->email]);
        $user->delete();

        return back()->with('success', 'User deleted.');
    }

    public function resetTwoFactor(User $user): RedirectResponse
    {
        $this->authorize('resetTwoFactor', $user);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->audit->log(AuditAction::UserTwoFactorReset, $user, ['email' => $user->email]);

        return back()->with('success', 'Two-factor authentication reset for '.$user->email.'.');
    }
}
