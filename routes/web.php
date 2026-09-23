<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\SelectionRuleController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'two-factor.required', 'can:panel.view'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::get('connections', [ConnectionController::class, 'index'])->name('connections.index');
    Route::post('connections', [ConnectionController::class, 'store'])->name('connections.store');
    Route::put('connections/{connection}', [ConnectionController::class, 'update'])->name('connections.update');
    Route::delete('connections/{connection}', [ConnectionController::class, 'destroy'])->name('connections.destroy');
    Route::post('connections/{connection}/test', [ConnectionController::class, 'test'])->name('connections.test');
    Route::post('connections/{connection}/discover', [ConnectionController::class, 'discover'])->name('connections.discover');

    Route::get('databases', [DatabaseController::class, 'index'])->name('databases.index');
    Route::post('databases/state', [DatabaseController::class, 'updateState'])->name('databases.state');

    Route::get('rules', [SelectionRuleController::class, 'index'])->name('rules.index');
    Route::get('rules/preview', [SelectionRuleController::class, 'preview'])->name('rules.preview');
    Route::post('rules', [SelectionRuleController::class, 'store'])->name('rules.store');
    Route::put('rules/{rule}', [SelectionRuleController::class, 'update'])->name('rules.update');
    Route::delete('rules/{rule}', [SelectionRuleController::class, 'destroy'])->name('rules.destroy');
    Route::post('connections/{connection}/apply-rules', [SelectionRuleController::class, 'apply'])->name('rules.apply');

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
