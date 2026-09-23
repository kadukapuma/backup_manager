<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Services\Settings\SettingsStore;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->checkPermissionTo(Permission::ManageSettings->value) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['age_public_key' => trim((string) $this->input('age_public_key'))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'age_public_key' => ['required', 'string', 'regex:'.SettingsStore::AGE_PUBLIC_KEY_PATTERN],
            'stale_after_hours' => ['required', 'integer', 'min:1', 'max:8760'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'age_public_key.regex' => 'This is not an age public key. It starts with "age1" and is 62 characters long (from age-keygen).',
        ];
    }
}
