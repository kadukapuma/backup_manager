<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Http\Requests\UpdateSystemSettingsRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsStore;
use App\Services\Tools\ToolRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemController extends Controller
{
    public function __construct(
        private readonly SettingsStore $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request, ToolRegistry $tools): Response
    {
        $canManage = $request->user()?->checkPermissionTo(Permission::ManageSettings->value) ?? false;
        $identityFile = (string) config('backup-manager.age_identity_file');

        return Inertia::render('system/index', [
            'canManage' => $canManage,
            'settings' => [
                'age_public_key' => $this->settings->agePublicKey() ?? '',
                'stale_after_hours' => $this->settings->staleAfterHours(),
            ],
            // Deferred: running the version commands can take a second or two.
            'tools' => $canManage ? Inertia::defer(fn (): array => $tools->check()) : [],
            'security' => [
                'ip_allowlist' => config('backup-manager.ip_allowlist'),
                'require_two_factor' => (bool) config('backup-manager.require_two_factor'),
                'age_identity_file_configured' => $identityFile !== '',
                'age_identity_file_readable' => $identityFile !== '' && is_readable($identityFile),
                'queue_connection' => config('queue.default'),
                'cache_store' => config('cache.default'),
                'timezone' => config('app.timezone'),
            ],
        ]);
    }

    public function update(UpdateSystemSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $before = [
            'age_public_key' => $this->settings->agePublicKey(),
            'stale_after_hours' => $this->settings->staleAfterHours(),
        ];

        $this->settings->set(SettingsStore::AGE_PUBLIC_KEY, $data['age_public_key']);
        $this->settings->set(SettingsStore::STALE_AFTER_HOURS, (int) $data['stale_after_hours']);

        $changes = [];
        foreach ($before as $key => $old) {
            $new = $key === 'stale_after_hours' ? (int) $data[$key] : $data[$key];
            if ($old !== $new) {
                $changes[$key] = ['from' => $old, 'to' => $new];
            }
        }

        if ($changes !== []) {
            $this->audit->log(AuditAction::SettingsChanged, null, ['changes' => $changes]);
        }

        return back()->with('success', 'Settings saved.');
    }
}
