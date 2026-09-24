<?php

use App\Modules\Procurement\Http\Controllers\PurchaseOrderController;
use App\Modules\Procurement\Http\Controllers\PurchaseRequestController;
use App\Modules\Procurement\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}')
    ->name('farms.procurement.')
    ->whereUuid(['supplier', 'purchaseRequest', 'order'])
    ->group(function () {
        Route::get('suppliers', [SupplierController::class, 'index'])->middleware('farm.can:suppliers.view')->name('suppliers.index');
        Route::middleware('farm.can:suppliers.manage')->group(function () {
            Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
            Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        });

        Route::middleware('farm.can:procurement.requests.create|procurement.requests.approve|procurement.orders.manage')->group(function () {
            Route::get('purchase-requests', [PurchaseRequestController::class, 'index'])->name('requests.index');
            Route::get('purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'show'])->name('requests.show');
        });
        Route::post('purchase-requests', [PurchaseRequestController::class, 'store'])->middleware('farm.can:procurement.requests.create')->name('requests.store');
        Route::post('purchase-requests/{purchaseRequest}/cancel', [PurchaseRequestController::class, 'cancel'])->middleware('farm.can:procurement.requests.create|procurement.requests.approve')->name('requests.cancel');
        Route::middleware('farm.can:procurement.requests.approve')->group(function () {
            Route::post('purchase-requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve'])->name('requests.approve');
            Route::post('purchase-requests/{purchaseRequest}/reject', [PurchaseRequestController::class, 'reject'])->name('requests.reject');
        });

        // The store sees orders to receive them (quantities, not prices).
        Route::middleware('farm.can:procurement.orders.manage|procurement.orders.approve|procurement.deliveries.receive')->group(function () {
            Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('orders.index');
            Route::get('purchase-orders/{order}', [PurchaseOrderController::class, 'show'])->name('orders.show');
        });
        Route::middleware('farm.can:procurement.orders.manage')->group(function () {
            Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('orders.store');
            Route::patch('purchase-orders/{order}', [PurchaseOrderController::class, 'update'])->name('orders.update');
            Route::post('purchase-orders/{order}/send', [PurchaseOrderController::class, 'send'])->name('orders.send');
            Route::post('purchase-orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('orders.cancel');
            Route::post('purchase-orders/{order}/close', [PurchaseOrderController::class, 'close'])->name('orders.close');
            Route::post('purchase-orders/{order}/invoices', [PurchaseOrderController::class, 'invoice'])->name('orders.invoice');
        });
        Route::post('purchase-orders/{order}/approve', [PurchaseOrderController::class, 'approve'])->middleware('farm.can:procurement.orders.approve')->name('orders.approve');
        Route::post('purchase-orders/{order}/deliveries', [PurchaseOrderController::class, 'receive'])->middleware('farm.can:procurement.deliveries.receive')->name('orders.receive');
        Route::get('supplier-invoices', [PurchaseOrderController::class, 'invoices'])->middleware('farm.can:procurement.orders.manage|finance.view')->name('invoices.index');
        Route::post('supplier-invoices/{supplierInvoice}/cancel', [PurchaseOrderController::class, 'cancelInvoice'])->middleware('farm.can:procurement.orders.manage|finance.manage')->whereUuid('supplierInvoice')->name('invoices.cancel');
    });
