<?php

use App\Enums\AuditAction;
use App\Enums\NotificationEvent;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Database;
use App\Models\NotificationChannel;
use App\Notifications\BackupAlert;
use App\Services\Notifications\Notifier;
use App\Services\Settings\SettingsStore;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

test('admin creates a mail channel with events', function () {
    actingAsRole(Role::Admin);

    $this->post('/notifications', [
        'name' => 'Ops', 'type' => 'mail', 'recipients' => 'ops@kreethya.com; admin@kreethya.com, ops@kreethya.com',
        'events' => ['run.failed', 'backup.stale'], 'is_active' => true,
    ])->assertSessionHasNoErrors();

    $channel = NotificationChannel::query()->firstOrFail();
    expect($channel->mailRecipients())->toBe(['ops@kreethya.com', 'admin@kreethya.com'])
        ->and($channel->events)->toBe(['run.failed', 'backup.stale']);
    expect(AuditLog::query()->where('action', AuditAction::NotificationChannelCreated->value)->exists())->toBeTrue();
});

test('invalid recipients and telegram are rejected in phase 1', function () {
    actingAsRole(Role::Admin);

    $this->post('/notifications', ['name' => 'x', 'type' => 'mail', 'recipients' => 'not-an-email', 'events' => ['run.failed'], 'is_active' => true])
        ->assertSessionHasErrors('recipients');
    $this->post('/notifications', ['name' => 'x', 'type' => 'telegram', 'recipients' => 'a@b.co', 'events' => ['run.failed'], 'is_active' => true])
        ->assertSessionHasErrors('type');
});

test('send test delivers to the channel', function () {
    $channel = NotificationChannel::factory()->create(['config' => ['recipients' => 'ops@kreethya.com']]);
    actingAsRole(Role::Admin);

    $this->post("/notifications/{$channel->id}/test")->assertSessionHas('success');

    Notification::assertSentTo(new AnonymousNotifiable, BackupAlert::class,
        fn (BackupAlert $n, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === ['ops@kreethya.com']);
});

test('notifier only sends to active channels subscribed to the event', function () {
    NotificationChannel::factory()->create(['events' => ['run.failed'], 'config' => ['recipients' => 'a@x.co']]);
    NotificationChannel::factory()->create(['events' => ['restore.failed'], 'config' => ['recipients' => 'b@x.co']]);
    NotificationChannel::factory()->create(['events' => ['run.failed'], 'is_active' => false, 'config' => ['recipients' => 'c@x.co']]);

    $sent = app(Notifier::class)->send(NotificationEvent::RunFailed, 'Subject', ['line']);

    expect($sent)->toBe(1);
});

test('viewers see channels but cannot change them', function () {
    $channel = NotificationChannel::factory()->create();
    actingAsRole(Role::Viewer);

    $this->get('/notifications')->assertOk();
    $this->post("/notifications/{$channel->id}/test")->assertForbidden();
    $this->delete("/notifications/{$channel->id}")->assertForbidden();
});

test('stale databases are reported once per day', function () {
    app(SettingsStore::class)->set(SettingsStore::STALE_AFTER_HOURS, 26);
    NotificationChannel::factory()->create(['events' => ['backup.stale']]);
    $stale = Database::factory()->create(['name' => 'old_one', 'last_success_at' => now()->subHours(30), 'first_seen_at' => now()->subDays(10)]);
    Database::factory()->create(['name' => 'fresh_one', 'last_success_at' => now()->subHours(2)]);
    Database::factory()->create(['name' => 'never_backed', 'last_success_at' => null, 'first_seen_at' => now()->subDays(3)]);
    Database::factory()->excluded()->create(['name' => 'ignored', 'last_success_at' => null, 'first_seen_at' => now()->subDays(3)]);
    Database::factory()->create(['name' => 'brand_new', 'last_success_at' => null, 'first_seen_at' => now()->subHour()]);

    $this->artisan('backup-manager:check-stale');
    $this->artisan('backup-manager:check-stale');

    Notification::assertSentTimes(BackupAlert::class, 1);
    Notification::assertSentTo(new AnonymousNotifiable, BackupAlert::class, function (BackupAlert $n) {
        $text = implode("\n", $n->lines);

        return $n->event === NotificationEvent::BackupStale
            && str_contains($text, 'old_one') && str_contains($text, 'never_backed')
            && ! str_contains($text, 'fresh_one') && ! str_contains($text, 'ignored') && ! str_contains($text, 'brand_new');
    });
});
