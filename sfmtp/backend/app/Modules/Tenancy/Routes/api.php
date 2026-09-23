<?php

use App\Modules\Tenancy\Http\Controllers\FarmController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api'])->group(function () {
    Route::get('farms', [FarmController::class, 'index'])->name('farms.index');
    Route::post('farms', [FarmController::class, 'store'])->name('farms.store');

    Route::prefix('farms/{farm}')->middleware('farm')->name('farms.')->group(function () {
        Route::get('/', [FarmController::class, 'show'])->middleware('farm.can:farm.profile.view')->name('show');
        Route::match(['put', 'patch'], '/', [FarmController::class, 'update'])->middleware('farm.can:farm.profile.manage')->name('update');
        Route::get('settings', [FarmController::class, 'settings'])->middleware('farm.can:farm.profile.view')->name('settings.show');
        Route::patch('settings', [FarmController::class, 'updateSettings'])->middleware('farm.can:farm.settings.manage')->name('settings.update');
        Route::delete('/', [FarmController::class, 'destroy'])->middleware('farm.can:farm.delete')->name('destroy');
    });
});
