<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationEvent;
use App\Models\Database;
use App\Services\Monitoring\StaleBackupDetector;
use App\Services\Notifications\Notifier;
use App\Services\Settings\SettingsStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Hourly: alert about databases without a recent successful backup. Each
 * database is reported at most once per day.
 */
class CheckStaleBackups extends Command
{
    protected $signature = 'backup-manager:check-stale';

    protected $description = 'Alert about included databases without a recent successful backup';

    public function handle(StaleBackupDetector $detector, Notifier $notifier, SettingsStore $settings): int
    {
        $fresh = $detector->stale()->filter(
            fn (Database $db): bool => Cache::add('bm:stale-alert:'.$db->id.':'.now()->format('Y-m-d'), true, now()->addDay()),
        );

        if ($fresh->isEmpty()) {
            return self::SUCCESS;
        }

        $lines = ['These included databases have no successful backup in the last '.$settings->staleAfterHours().' hours:'];
        foreach ($fresh->take(50) as $db) {
            $lines[] = "• {$db->serverConnection->name} / {$db->name}: last success "
                .($db->last_success_at?->diffForHumans() ?? 'never');
        }
        if ($fresh->count() > 50) {
            $lines[] = '…and '.($fresh->count() - 50).' more.';
        }

        $notifier->send(NotificationEvent::BackupStale, $fresh->count().' database(s) without a recent backup', $lines, url('/dashboard'));
        $this->warn("Reported {$fresh->count()} stale database(s).");

        return self::SUCCESS;
    }
}
