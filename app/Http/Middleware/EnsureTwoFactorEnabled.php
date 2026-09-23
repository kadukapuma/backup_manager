<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When BM_REQUIRE_2FA=true, users without confirmed 2FA can only reach the
 * account settings pages (to enable it) and log out.
 */
class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            ! config('backup-manager.require_two_factor')
            || ! $user instanceof User
            || $user->hasTwoFactorEnabled()
            || $request->is('settings', 'settings/*', 'user/*', 'logout', 'confirm-password')
        ) {
            return $next($request);
        }

        return redirect()->route('two-factor.show')
            ->with('error', 'Two-factor authentication is required. Please enable it to continue.');
    }
}
