<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationChannelType: string
{
    use HasOptions;

    case Mail = 'mail';
    case Telegram = 'telegram';

    public function label(): string
    {
        return match ($this) {
            self::Mail => 'Email',
            self::Telegram => 'Telegram (planned)',
        };
    }

    public function isAvailable(): bool
    {
        return $this === self::Mail;
    }
}
