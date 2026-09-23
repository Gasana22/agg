<?php

use App\Modules\Platform\Http\Controllers\AdminFarmController;
use App\Modules\Platform\Http\Controllers\AdminIntegrationController;
use App\Modules\Platform\Http\Controllers\AdminSettingsController;
use App\Modules\Platform\Http\Controllers\AdminSystemController;
use App\Modules\Platform\Http\Controllers\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'platform.admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::prefix('farms')->name('farms.')->whereUuid('farm')->group(function () {
            Route::middleware('platform.can:farms.view')->group(function () {
                Route::get('/', [AdminFarmController::class, 'index'])->name('index');
                Route::get('{farm}', [AdminFarmController::class, 'show'])->name('show');
            });
            Route::post('{farm}/approve', [AdminFarmController::class, 'approve'])->middleware('platform.can:farms.approve')->name('approve');
            // Billing staff hold farms.suspend (non-payment only, enforced in FarmAdministration).
            Route::post('{farm}/suspend', [AdminFarmController::class, 'suspend'])->middleware('platform.can:farms.suspend')->name('suspend');
            Route::post('{farm}/unsuspend', [AdminFarmController::class, 'unsuspend'])->middleware('platform.can:farms.suspend')->name('unsuspend');
            Route::post('{farm}/reset-owner-password', [AdminFarmController::class, 'resetOwnerPassword'])->middleware('platform.can:farms.reset_owner_password')->name('reset-owner-password');
        });

        Route::prefix('users')->name('users.')->whereUuid('user')->group(function () {
            Route::get('/', [AdminUserController::class, 'index'])->middleware('platform.can:users.view')->name('index');
            Route::post('/', [AdminUserController::class, 'store'])->middleware('platform.can:users.manage')->name('store');
            Route::patch('{user}', [AdminUserController::class, 'update'])->middleware('platform.can:users.manage')->name('update');
        });

        Route::middleware('platform.can:settings.manage')->group(function () {
            Route::get('settings', [AdminSettingsController::class, 'show'])->name('settings.show');
            Route::put('settings', [AdminSettingsController::class, 'update'])->name('settings.update');
        });

        Route::middleware('platform.can:integrations.manage')->prefix('integrations')->name('integrations.')->whereUuid('integration')->group(function () {
            Route::get('/', [AdminIntegrationController::class, 'index'])->name('index');
            Route::post('/', [AdminIntegrationController::class, 'store'])->name('store');
            Route::patch('{integration}', [AdminIntegrationController::class, 'update'])->name('update');
            Route::delete('{integration}', [AdminIntegrationController::class, 'destroy'])->name('destroy');
        });

        Route::middleware('platform.can:system.view')->prefix('system')->name('system.')->group(function () {
            Route::get('health', [AdminSystemController::class, 'health'])->name('health');
            Route::get('audit-logs', [AdminSystemController::class, 'auditLogs'])->name('audit-logs');
            Route::get('failed-jobs', [AdminSystemController::class, 'failedJobs'])->name('failed-jobs');
            Route::get('backups', [AdminSystemController::class, 'backups'])->name('backups');
        });
    });
