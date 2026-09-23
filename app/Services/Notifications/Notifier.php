<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannelType;
use App\Enums\NotificationEvent;
use App\Models\NotificationChannel;
use App\Notifications\BackupAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends an alert to every active channel subscribed to the event. Delivery
 * failures are logged and never break the calling job.
 */
class Notifier
{
    /**
     * @param  list<string>  $lines
     */
    public function send(NotificationEvent $event, string $subject, array $lines, ?string $url = null): int
    {
        $sent = 0;

        $channels = NotificationChannel::query()->where('is_active', true)->get()
            ->filter(fn (NotificationChannel $c): bool => in_array($event->value, $c->events, true));

        foreach ($channels as $channel) {
            if ($this->deliver($channel, new BackupAlert($event, $subject, $lines, $url))) {
                $sent++;
            }
        }

        return $sent;
    }

    public function deliver(NotificationChannel $channel, BackupAlert $alert): bool
    {
        try {
            if ($channel->type === NotificationChannelType::Mail) {
                $recipients = $channel->mailRecipients();
                if ($recipients === []) {
                    return false;
                }
                Notification::route('mail', $recipients)->notifyNow($alert);

                return true;
            }
        } catch (Throwable $e) {
            Log::warning('Notification delivery failed', ['channel' => $channel->id, 'error' => $e->getMessage()]);
        }

        return false;
    }
}
