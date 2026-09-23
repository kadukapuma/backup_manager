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
            'socket' => ['nullable', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9_.\-\/]+$/'],
            'new_database_policy' => ['required', Rule::enum(NewDatabasePolicy::class)],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
