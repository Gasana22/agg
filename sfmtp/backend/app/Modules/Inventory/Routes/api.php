<?php

use App\Modules\Inventory\Http\Controllers\AdjustmentController;
use App\Modules\Inventory\Http\Controllers\ItemController;
use App\Modules\Inventory\Http\Controllers\RequestController;
use App\Modules\Inventory\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}')
    ->name('farms.inventory.')
    ->whereUuid(['item', 'adjustment', 'inventoryRequest'])
    ->group(function () {
        Route::middleware('farm.can:inventory.view')->group(function () {
            Route::get('inventory/items/{item}', [ItemController::class, 'show'])->name('items.show');
            Route::get('inventory/stock', [StockController::class, 'balances'])->name('stock.index');
            Route::get('inventory/movements', [StockController::class, 'movements'])->name('movements.index');
            Route::get('inventory/transfers', [StockController::class, 'transfers'])->name('transfers.index');
            Route::get('inventory/alerts', [StockController::class, 'alerts'])->name('alerts');
            Route::get('inventory/adjustments', [AdjustmentController::class, 'index'])->name('adjustments.index');
        });
        // Requesters pick items too (names and units only; values need inventory.values.view).
        Route::get('inventory/items', [ItemController::class, 'index'])->middleware('farm.can:inventory.view|inventory.requests.create')->name('items.index');
        Route::middleware('farm.can:inventory.manage')->group(function () {
            Route::post('inventory/items', [ItemController::class, 'store'])->name('items.store');
            Route::patch('inventory/items/{item}', [ItemController::class, 'update'])->name('items.update');
        });
        Route::middleware('farm.can:inventory.stock.move')->group(function () {
            Route::post('inventory/stock-in', [StockController::class, 'stockIn'])->name('stock.in');
            Route::post('inventory/issues', [StockController::class, 'issue'])->name('stock.issue');
            Route::post('inventory/transfers', [StockController::class, 'transfer'])->name('transfers.store');
            Route::post('inventory/requests/{inventoryRequest}/issue', [RequestController::class, 'issue'])->name('requests.issue');
        });
        Route::post('inventory/adjustments', [AdjustmentController::class, 'store'])->middleware('farm.can:inventory.stock.adjust')->name('adjustments.store');
        Route::middleware('farm.can:inventory.stock.approve')->group(function () {
            Route::post('inventory/adjustments/{adjustment}/approve', [AdjustmentController::class, 'approve'])->name('adjustments.approve');
            Route::post('inventory/adjustments/{adjustment}/reject', [AdjustmentController::class, 'reject'])->name('adjustments.reject');
            Route::post('inventory/requests/{inventoryRequest}/approve', [RequestController::class, 'approve'])->name('requests.approve');
            Route::post('inventory/requests/{inventoryRequest}/reject', [RequestController::class, 'reject'])->name('requests.reject');
        });
        Route::middleware('farm.can:inventory.requests.create|inventory.stock.move|inventory.stock.approve')->group(function () {
            Route::get('inventory/requests', [RequestController::class, 'index'])->name('requests.index');
            Route::get('inventory/requests/{inventoryRequest}', [RequestController::class, 'show'])->name('requests.show');
            Route::post('inventory/requests/{inventoryRequest}/cancel', [RequestController::class, 'cancel'])->name('requests.cancel');
        });
        Route::post('inventory/requests', [RequestController::class, 'store'])->middleware('farm.can:inventory.requests.create')->name('requests.store');
    });
