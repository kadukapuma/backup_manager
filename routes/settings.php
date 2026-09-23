<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');

    // Two-factor authentication (Fortify controllers, password confirmation required).
    Route::middleware('password.confirm')->group(function () {
        Route::get('settings/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
        Route::post('user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'store'])
            ->name('two-factor.enable');
        Route::post('user/confirmed-two-factor-authentication', [ConfirmedTwoFactorAuthenticationController::class, 'store'])
            ->name('two-factor.confirm');
        Route::delete('user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'destroy'])
            ->name('two-factor.disable');
        Route::post('user/two-factor-recovery-codes', [RecoveryCodeController::class, 'store'])
            ->name('two-factor.regenerate-recovery-codes');
    });
});
