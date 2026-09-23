<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\MfaController;
use App\Modules\Identity\Http\Controllers\PasswordController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('mfa/challenge', [AuthController::class, 'mfaChallenge'])->name('mfa.challenge');
        Route::post('password/forgot', [PasswordController::class, 'forgot'])->name('password.forgot');
        Route::post('password/reset', [PasswordController::class, 'reset'])->name('password.reset');
    });
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:refresh')->name('refresh');

    Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('mfa/setup', [MfaController::class, 'setup'])->name('mfa.setup');
        Route::post('mfa/confirm', [MfaController::class, 'confirm'])->name('mfa.confirm');
        Route::post('mfa/recovery-codes', [MfaController::class, 'recoveryCodes'])->name('mfa.recovery-codes');
        Route::delete('mfa', [MfaController::class, 'disable'])->name('mfa.disable');
    });
});

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api'])->prefix('me')->name('me.')->group(function () {
    Route::get('/', [MeController::class, 'show'])->name('show');
    Route::get('devices', [MeController::class, 'devices'])->name('devices');
    Route::delete('devices/{device}', [MeController::class, 'revokeDevice'])->whereUuid('device')->name('devices.revoke');
});
