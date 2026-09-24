<?php

use App\Modules\Sync\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

// Any member may sync; each mutation is checked against its own permission.
Route::middleware(['auth:api', 'mfa.compliant', 'farm'])
    ->prefix('farms/{farm}/sync')
    ->name('farms.sync.')
    ->group(function () {
        Route::post('push', [SyncController::class, 'push'])->middleware('throttle:sync')->name('push');
        Route::get('pull', [SyncController::class, 'pull'])->middleware('throttle:api')->name('pull');
        Route::get('conflicts', [SyncController::class, 'conflicts'])->middleware('throttle:api')->name('conflicts.index');
        Route::post('conflicts/{syncConflict}/resolve', [SyncController::class, 'resolve'])->middleware('throttle:api')->whereUuid('syncConflict')->name('conflicts.resolve');
    });
