<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationEvent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackupAlert extends Notification
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly NotificationEvent $event,
        public readonly string $subject,
        public readonly array $lines,
        public readonly ?string $url = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isProblem = in_array($this->event, [
            NotificationEvent::RunFailed,
            NotificationEvent::RunPartial,
            NotificationEvent::RestoreFailed,
            NotificationEvent::BackupStale,
            NotificationEvent::DatabaseMissing,
        ], true);

        $message = (new MailMessage)
            ->subject('['.config('app.name').'] '.$this->subject)
            ->greeting($this->subject);

        if ($isProblem) {
            $message->error();
        }

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        if ($this->url !== null) {
            $message->action('Open Backup Manager', $this->url);
        }

        return $message->line('Event: '.$this->event->label().' · '.now()->format('Y-m-d H:i T'));
    }
}
