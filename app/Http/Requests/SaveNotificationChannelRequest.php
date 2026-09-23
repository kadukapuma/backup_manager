<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\NotificationEvent;
use App\Models\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveNotificationChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $channel = $this->route('channel');

        return $channel instanceof NotificationChannel
            ? ($this->user()?->can('update', $channel) ?? false)
            : ($this->user()?->can('create', NotificationChannel::class) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            // Phase 1 delivers email only; Telegram is on the roadmap.
            'type' => ['required', Rule::in(['mail'])],
            'recipients' => ['required', 'string', 'max:2000'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::enum(NotificationEvent::class)],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->recipientList() as $email) {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        $validator->errors()->add('recipients', "\"{$email}\" is not a valid email address.");
                    }
                }
                if ($this->recipientList() === []) {
                    $validator->errors()->add('recipients', 'Enter at least one email address.');
                }
            },
        ];
    }

    /**
     * @return list<string>
     */
    public function recipientList(): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $this->input('recipients')) ?: []))));
    }
}
