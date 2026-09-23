<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AuditAction;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects requests from IPs outside BM_IP_ALLOWLIST. Disabled when the list is empty.
 */
class IpAllowlist
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $allowed */
        $allowed = config('backup-manager.ip_allowlist', []);

        if ($allowed === [] || $request->is('up')) {
            return $next($request);
        }

        $ip = (string) $request->ip();

        if (! IpUtils::checkIp($ip, $allowed)) {
            // Log each blocked IP at most once per 10 minutes to keep the audit log readable.
            if (RateLimiter::attempt('bm-ip-denied:'.$ip, 1, static fn () => true, 600)) {
                $this->audit->log(AuditAction::AccessDeniedByIp, null, ['path' => $request->path()]);
            }

            abort(403, 'Access from your IP address is not allowed.');
        }

        return $next($request);
    }
}
