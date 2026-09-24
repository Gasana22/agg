<?php

use App\Modules\Finance\Http\Controllers\LedgerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}/ledger')
    ->name('farms.ledger.')
    ->whereUuid(['entry'])
    ->group(function () {
        Route::middleware('farm.can:finance.view')->group(function () {
            Route::get('accounts', [LedgerController::class, 'accounts'])->name('accounts');
            Route::get('entries', [LedgerController::class, 'entries'])->name('entries.index');
            Route::get('entries/{entry}', [LedgerController::class, 'show'])->name('entries.show');
        });
        Route::post('entries/{entry}/reverse', [LedgerController::class, 'reverse'])->middleware('farm.can:finance.manage')->name('entries.reverse');
    });
