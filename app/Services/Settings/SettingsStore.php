<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * UI-editable settings with config fallbacks, cached for a minute.
 */
class SettingsStore
{
    public const AGE_PUBLIC_KEY = 'age_public_key';

    public const STALE_AFTER_HOURS = 'stale_after_hours';

    public const AGE_PUBLIC_KEY_PATTERN = '/^age1[02-9ac-hj-np-z]{58}$/';

    public function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::remember('bm:settings', 60, fn (): array => Setting::query()->pluck('value', 'key')->all());

        return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('bm:settings');
    }

    public function agePublicKey(): ?string
    {
        $key = $this->get(self::AGE_PUBLIC_KEY);

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function staleAfterHours(): int
    {
        return max(1, (int) $this->get(self::STALE_AFTER_HOURS, config('backup-manager.stale_after_hours', 26)));
    }
}
