<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'two-factor.required', 'can:panel.view'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::post('users/{user}/reset-two-factor', [UserController::class, 'resetTwoFactor'])->name('users.reset-two-factor');

    Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit.index');

    Route::get('system', [SystemController::class, 'show'])->name('system.show');
    Route::put('system', [SystemController::class, 'update'])->name('system.update');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
