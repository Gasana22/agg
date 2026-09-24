<?php

use App\Modules\Notifications\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

// Every member has an inbox; each sees only their own notifications.
Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}/notifications')
    ->name('farms.notifications.')
    ->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('read-all', [NotificationController::class, 'readAll'])->name('read-all');
        Route::post('{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('read');
    });

Route::middleware(['auth:api', 'throttle:api'])->put('me/devices/current/push-token', [NotificationController::class, 'pushToken'])->name('me.devices.push-token');
