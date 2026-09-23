<?php

namespace Database\Factories;

use App\Enums\NotificationChannelType;
use App\Enums\NotificationEvent;
use App\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    protected $model = NotificationChannel::class;

    public function definition(): array
    {
        return [
            'name' => 'Ops email',
            'type' => NotificationChannelType::Mail,
            'config' => ['recipients' => 'ops@example.com'],
            'events' => NotificationEvent::defaults(),
            'is_active' => true,
        ];
    }
}
