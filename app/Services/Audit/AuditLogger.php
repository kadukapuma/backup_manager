<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SecretRedactor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Writes append-only audit entries. Secrets in $meta are always redacted.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(
        AuditAction $action,
        ?Model $subject = null,
        array $meta = [],
        Authenticatable|int|null $user = null,
    ): AuditLog {
        $userId = match (true) {
            $user instanceof Authenticatable => (int) $user->getAuthIdentifier(),
            is_int($user) => $user,
            default => Auth::id() !== null ? (int) Auth::id() : null,
        };

        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return AuditLog::query()->create([
            'user_id' => $userId !== null && User::query()->whereKey($userId)->exists() ? $userId : null,
            'action' => $action->value,
            'subject_type' => $subject !== null ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'ip' => $request?->ip(),
            'user_agent' => $request !== null ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'meta' => $meta === [] ? null : SecretRedactor::redactArray($meta),
        ]);
    }
}
