<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shows the 2FA settings page. Enabling, confirming and disabling go to
 * Fortify's controllers (see routes/settings.php).
 */
class TwoFactorController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $hasSecret = $user->two_factor_secret !== null;
        $confirmed = $user->hasTwoFactorEnabled();

        return Inertia::render('settings/two-factor', [
            'enabled' => $hasSecret,
            'confirmed' => $confirmed,
            'required' => (bool) config('backup-manager.require_two_factor'),
            'qrCodeSvg' => $hasSecret && ! $confirmed ? $user->twoFactorQrCodeSvg() : null,
            'setupKey' => $hasSecret && ! $confirmed ? decrypt($user->two_factor_secret) : null,
            'recoveryCodes' => $confirmed ? $user->recoveryCodes() : [],
        ]);
    }
}
