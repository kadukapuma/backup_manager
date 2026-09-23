<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && ($this->user()?->can('update', $target) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($target->id)],
            'role' => ['required', Rule::enum(Role::class)],
            'password' => ['nullable', 'confirmed', Password::min(12)],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var User $target */
                $target = $this->route('user');
                if ($target->is($this->user()) && $this->input('role') !== Role::Admin->value) {
                    $validator->errors()->add('role', 'You cannot remove your own admin role.');
                }
            },
        ];
    }
}
