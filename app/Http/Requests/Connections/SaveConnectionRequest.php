<?php

declare(strict_types=1);

namespace App\Http\Requests\Connections;

use App\Enums\ConnectionDriver;
use App\Enums\NewDatabasePolicy;
use App\Models\ServerConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and update. On update a blank password keeps the stored one.
 */
class SaveConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $connection = $this->route('connection');

        return $connection instanceof ServerConnection
            ? ($this->user()?->can('update', $connection) ?? false)
            : ($this->user()?->can('create', ServerConnection::class) ?? false);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'socket.prohibited_if_accepted' => 'A socket cannot be used through SSH. Use the host and port as seen from the SSH server (usually 127.0.0.1).',
            'ssh_host.required_if_accepted' => 'Enter the SSH server address.',
            'ssh_user.required_if_accepted' => 'Enter the SSH user on that server.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $connection = $this->route('connection');
        $noBreaks = 'not_regex:/[\r\n\0]/';

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('connections', 'name')->ignore($connection instanceof ServerConnection ? $connection->id : null)],
            'driver' => ['required', Rule::in(ConnectionDriver::supportedValues())],
            'host' => ['required_without:socket', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:80', $noBreaks],
            'password' => ['nullable', 'string', 'max:255', $noBreaks],
            'socket' => ['nullable', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9_.\-\/]+$/', 'prohibited_if_accepted:ssh_enabled'],
            'ssh_enabled' => ['sometimes', 'boolean'],
            'ssh_host' => ['nullable', 'required_if_accepted:ssh_enabled', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'ssh_port' => ['nullable', 'integer', 'between:1,65535'],
            'ssh_user' => ['nullable', 'required_if_accepted:ssh_enabled', 'string', 'max:100', 'regex:/^[A-Za-z0-9_][A-Za-z0-9_.\-]*$/'],
            'ssh_forget_host_key' => ['sometimes', 'boolean'],
            'new_database_policy' => ['required', Rule::enum(NewDatabasePolicy::class)],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
