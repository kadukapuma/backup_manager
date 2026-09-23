<?php

use App\Jobs\DiscoverDatabasesJob;
use App\Models\ServerConnection;
use Illuminate\Support\Facades\Schedule;

/*
 * Cron on the server:  * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
 * All times are Asia/Colombo (APP_TIMEZONE).
 */

Schedule::call(function (): void {
    ServerConnection::query()->where('is_active', true)->pluck('id')
        ->each(fn (int $id) => DiscoverDatabasesJob::dispatch($id));
})->name('discover-databases')->everyFifteenMinutes()->withoutOverlapping();
