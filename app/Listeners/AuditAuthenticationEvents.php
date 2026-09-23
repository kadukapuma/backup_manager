<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

/**
 * Discovered automatically by Laravel's event discovery (handle* methods).
 */
class AuditAuthenticationEvents
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handleLogin(Login $event): void
    {
        $this->audit->log(AuditAction::Login, null, ['remember' => $event->remember], $event->user);
    }

    public function handleFailed(Failed $event): void
    {
        $this->audit->log(
            AuditAction::LoginFailed,
            null,
            ['email' => (string) ($event->credentials['email'] ?? '')],
            $event->user,
        );
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user !== null) {
            $this->audit->log(AuditAction::Logout, null, [], $event->user);
        }
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->audit->log(AuditAction::TwoFactorEnabled, null, [], $event->user);
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->audit->log(AuditAction::TwoFactorDisabled, null, [], $event->user);
    }
}
