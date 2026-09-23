<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\Database;
use App\Services\Settings\SettingsStore;
use Illuminate\Database\Eloquent\Collection;

/**
 * Included databases whose newest successful backup is older than the
 * threshold (or that were never backed up since discovery longer ago than it).
 */
class StaleBackupDetector
{
    public function __construct(private readonly SettingsStore $settings) {}

    /**
     * @return Collection<int, Database>
     */
    public function stale(): Collection
    {
        $cutoff = now()->subHours($this->settings->staleAfterHours());

        return Database::query()
            ->with('serverConnection:id,name,is_active')
            ->included()
            ->whereHas('serverConnection', fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q
                ->where('last_success_at', '<', $cutoff)
                ->orWhere(fn ($q2) => $q2->whereNull('last_success_at')->where('first_seen_at', '<', $cutoff)))
            ->orderBy('last_success_at')
            ->get();
    }
}
