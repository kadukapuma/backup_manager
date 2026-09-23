<?php

declare(strict_types=1);

namespace App\Http\Requests\Destinations;

use App\Enums\DestinationType;
use App\Models\Destination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveDestinationRequest extends FormRequest
{
    public const S3_PROVIDERS = ['AWS', 'Wasabi', 'Cloudflare', 'DigitalOcean', 'Minio', 'Backblaze', 'Other'];

    public function authorize(): bool
    {
        $destination = $this->route('destination');

        return $destination instanceof Destination
            ? ($this->user()?->can('update', $destination) ?? false)
            : ($this->user()?->can('create', Destination::class) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $destination = $this->route('destination');
        $type = $destination instanceof Destination ? $destination->type->value : $this->input('type');
        $isNew = ! $destination instanceof Destination;
        $noBreaks = 'not_regex:/[\r\n\0]/';
        $safePath = 'regex:/^[A-Za-z0-9_.\-\/ ]*$/';

        $rules = [
            'name' => ['required', 'string', 'max:100', Rule::unique('destinations', 'name')->ignore($destination instanceof Destination ? $destination->id : null)],
            'type' => [$isNew ? 'required' : 'sometimes', Rule::in(DestinationType::availableValues())],
            'base_path' => ['nullable', 'string', 'max:255', $safePath, 'not_regex:/(^|\/)\.\.(\/|$)/'],
            'is_active' => ['required', 'boolean'],
            'config' => ['array'],
        ];

        return match ($type) {
            DestinationType::Local->value => [
                ...$rules,
                'base_path' => ['required', 'string', 'max:255', 'starts_with:/', $safePath, 'not_regex:/(^|\/)\.\.(\/|$)/'],
            ],
            DestinationType::Sftp->value => [
                ...$rules,
                'config.host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
                'config.port' => ['required', 'integer', 'between:1,65535'],
                'config.user' => ['required', 'string', 'max:100', $noBreaks],
                'config.password' => ['nullable', 'string', 'max:255', $noBreaks],
                'config.private_key' => ['nullable', 'string', 'max:10000'],
                'config.private_key_passphrase' => ['nullable', 'string', 'max:255', $noBreaks],
                'config.known_hosts_file' => ['nullable', 'string', 'max:255', 'starts_with:/', $safePath],
            ],
            DestinationType::S3->value => [
                ...$rules,
                'config.provider' => ['required', Rule::in(self::S3_PROVIDERS)],
                'config.region' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9\-]*$/'],
                'config.endpoint' => ['nullable', 'url:https,http', 'max:255'],
                'config.bucket' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/'],
                'config.access_key_id' => ['required', 'string', 'max:128', $noBreaks],
                'config.secret_access_key' => ['nullable', 'string', 'max:255', $noBreaks],
                'config.storage_class' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z_]*$/'],
            ],
            default => $rules,
        };
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $destination = $this->route('destination');
                $type = $destination instanceof Destination ? $destination->type : DestinationType::tryFrom((string) $this->input('type'));
                $stored = $destination instanceof Destination ? $destination->config : [];
                $has = fn (string $key): bool => ! empty($this->input("config.{$key}")) || ! empty($stored[$key] ?? null);

                if ($type === DestinationType::Sftp && ! $has('password') && ! $has('private_key')) {
                    $validator->errors()->add('config.password', 'Enter a password or a private key.');
                }
                if ($type === DestinationType::S3 && ! $has('secret_access_key')) {
                    $validator->errors()->add('config.secret_access_key', 'The secret access key is required.');
                }
            },
        ];
    }
}
