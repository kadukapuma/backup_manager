<?php

namespace App\Providers;

use App\Services\Database\DatabaseServerClient;
use App\Services\Database\PdoDatabaseServerClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DatabaseServerClient::class, PdoDatabaseServerClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
