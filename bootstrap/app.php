<?php

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IpAllowlist;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $trustedProxies = (string) env('BM_TRUSTED_PROXIES', '');
        if ($trustedProxies !== '') {
            $middleware->trustProxies(at: $trustedProxies === '*' ? '*' : array_map('trim', explode(',', $trustedProxies)));
        }

        $middleware->web(prepend: [
            IpAllowlist::class,
        ], append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'two-factor.required' => EnsureTwoFactorEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
