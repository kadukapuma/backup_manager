<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AuditLog::class);

        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:64'],
            'subject_type' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name, 'email' => $log->user->email] : null,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'ip' => $log->ip,
                'user_agent' => $log->user_agent,
                'meta' => $log->meta,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return Inertia::render('audit/index', [
            'logs' => $logs,
            'filters' => [
                'user_id' => isset($filters['user_id']) ? (string) $filters['user_id'] : '',
                'action' => $filters['action'] ?? '',
                'subject_type' => $filters['subject_type'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'actions' => AuditAction::options(),
            'users' => User::query()->orderBy('name')->get(['id', 'name'])->map(fn (User $u): array => ['value' => (string) $u->id, 'label' => $u->name]),
            'subjectTypes' => AuditLog::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ]);
    }
}
