<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BackupPlanController;
use App\Http\Controllers\BackupRunController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\NotificationChannelController;
use App\Http\Controllers\RestoreController;
use App\Http\Controllers\SelectionRuleController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'two-factor.required', 'can:panel.view'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

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

    Route::get('destinations', [DestinationController::class, 'index'])->name('destinations.index');
    Route::post('destinations', [DestinationController::class, 'store'])->name('destinations.store');
    Route::put('destinations/{destination}', [DestinationController::class, 'update'])->name('destinations.update');
    Route::delete('destinations/{destination}', [DestinationController::class, 'destroy'])->name('destinations.destroy');
    Route::post('destinations/{destination}/test', [DestinationController::class, 'test'])->name('destinations.test');

    Route::get('plans', [BackupPlanController::class, 'index'])->name('plans.index');
    Route::get('plans/cron-preview', [BackupPlanController::class, 'cronPreview'])->name('plans.cron-preview');
    Route::post('plans', [BackupPlanController::class, 'store'])->name('plans.store');
    Route::put('plans/{plan}', [BackupPlanController::class, 'update'])->name('plans.update');
    Route::delete('plans/{plan}', [BackupPlanController::class, 'destroy'])->name('plans.destroy');

    Route::post('plans/{plan}/run', [BackupController::class, 'runPlan'])->name('plans.run');
    Route::post('backups/manual', [BackupController::class, 'manual'])->name('backups.manual');
    Route::get('backups/{file}/download', [BackupController::class, 'download'])->name('backups.download');
    Route::delete('backups/{file}', [BackupController::class, 'destroy'])->name('backups.destroy');

    Route::get('runs', [BackupRunController::class, 'index'])->name('runs.index');
    Route::get('runs/{run}', [BackupRunController::class, 'show'])->name('runs.show');

    Route::get('restores', [RestoreController::class, 'index'])->name('restores.index');
    Route::get('restores/new', [RestoreController::class, 'create'])->name('restores.create');
    Route::post('restores', [RestoreController::class, 'store'])->name('restores.store');
    Route::get('restores/{restore}', [RestoreController::class, 'show'])->name('restores.show');

    Route::get('notifications', [NotificationChannelController::class, 'index'])->name('notifications.index');
    Route::post('notifications', [NotificationChannelController::class, 'store'])->name('notifications.store');
    Route::put('notifications/{channel}', [NotificationChannelController::class, 'update'])->name('notifications.update');
    Route::delete('notifications/{channel}', [NotificationChannelController::class, 'destroy'])->name('notifications.destroy');
    Route::post('notifications/{channel}/test', [NotificationChannelController::class, 'test'])->name('notifications.test');

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
