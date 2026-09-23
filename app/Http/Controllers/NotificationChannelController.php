<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationEvent;
use App\Http\Requests\SaveNotificationChannelRequest;
use App\Models\NotificationChannel;
use App\Notifications\BackupAlert;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\Notifier;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class NotificationChannelController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('viewAny', NotificationChannel::class);

        return Inertia::render('notifications/index', [
            'channels' => NotificationChannel::query()->orderBy('name')->get()->map(fn (NotificationChannel $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type->value,
                'recipients' => implode(', ', $c->mailRecipients()),
                'events' => $c->events,
                'is_active' => $c->is_active,
            ]),
            'events' => NotificationEvent::options(),
            'defaultEvents' => NotificationEvent::defaults(),
            'types' => array_map(
                fn (NotificationChannelType $t): array => ['value' => $t->value, 'label' => $t->label(), 'available' => $t->isAvailable()],
                NotificationChannelType::cases(),
            ),
            'mailer' => config('mail.default'),
        ]);
    }

    public function store(SaveNotificationChannelRequest $request): RedirectResponse
    {
        $channel = NotificationChannel::query()->create($this->attributes($request));
        $this->audit->log(AuditAction::NotificationChannelCreated, $channel, ['name' => $channel->name, 'events' => $channel->events]);

        return back()->with('success', 'Notification channel created.');
    }

    public function update(SaveNotificationChannelRequest $request, NotificationChannel $channel): RedirectResponse
    {
        $channel->update($this->attributes($request));
        $this->audit->log(AuditAction::NotificationChannelUpdated, $channel, ['name' => $channel->name, 'events' => $channel->events]);

        return back()->with('success', 'Notification channel updated.');
    }

    public function destroy(NotificationChannel $channel): RedirectResponse
    {
        $this->authorize('delete', $channel);

        $this->audit->log(AuditAction::NotificationChannelDeleted, $channel, ['name' => $channel->name]);
        $channel->delete();

        return back()->with('success', 'Notification channel deleted.');
    }

    public function test(NotificationChannel $channel, Notifier $notifier): RedirectResponse
    {
        $this->authorize('test', $channel);

        $ok = $notifier->deliver($channel, new BackupAlert(
            NotificationEvent::RunSuccess,
            'Test notification',
            ['This is a test message from '.config('app.name').'. If you can read it, the channel works.'],
            url('/notifications'),
        ));
        $this->audit->log(AuditAction::NotificationTestSent, $channel, ['name' => $channel->name, 'ok' => $ok]);

        return $ok
            ? back()->with('success', 'Test message sent to '.implode(', ', $channel->mailRecipients()).'.')
            : back()->with('error', 'Sending failed. Check the MAIL_* settings in .env and the application log.');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(SaveNotificationChannelRequest $request): array
    {
        return [
            'name' => $request->validated('name'),
            'type' => NotificationChannelType::Mail,
            'config' => ['recipients' => implode(',', $request->recipientList())],
            'events' => array_values(array_unique($request->validated('events'))),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
