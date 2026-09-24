<?php

use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\CustomerInvoiceController;
use App\Modules\Sales\Http\Controllers\ShipmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}')
    ->name('farms.')
    ->whereUuid(['customer', 'customerInvoice', 'shipment'])
    ->group(function () {
        Route::get('customers', [CustomerController::class, 'index'])->middleware('farm.can:customers.view|sales.invoice|sales.fulfil')->name('customers.index');
        Route::middleware('farm.can:customers.manage')->group(function () {
            Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
            Route::patch('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        });

        Route::name('customer-invoices.')->prefix('customer-invoices')->group(function () {
            Route::middleware('farm.can:sales.invoice|finance.view')->group(function () {
                Route::get('/', [CustomerInvoiceController::class, 'index'])->name('index');
                Route::get('{customerInvoice}', [CustomerInvoiceController::class, 'show'])->name('show');
            });
            Route::middleware('farm.can:sales.invoice')->group(function () {
                Route::get('billable/livestock-sales', [CustomerInvoiceController::class, 'billableSales'])->name('billable');
                Route::post('/', [CustomerInvoiceController::class, 'store'])->name('store');
                Route::patch('{customerInvoice}', [CustomerInvoiceController::class, 'update'])->name('update');
                Route::post('{customerInvoice}/issue', [CustomerInvoiceController::class, 'issue'])->name('issue');
                Route::post('{customerInvoice}/void', [CustomerInvoiceController::class, 'void'])->name('void');
            });
        });

        // Shipments: no prices, so the store can dispatch and confirm delivery.
        Route::name('shipments.')->prefix('shipments')->group(function () {
            Route::middleware('farm.can:sales.view|sales.fulfil|sales.invoice')->group(function () {
                Route::get('/', [ShipmentController::class, 'index'])->name('index');
                Route::get('{shipment}', [ShipmentController::class, 'show'])->name('show');
            });
            Route::middleware('farm.can:sales.fulfil')->group(function () {
                Route::post('/', [ShipmentController::class, 'store'])->name('store');
                Route::post('{shipment}/deliver', [ShipmentController::class, 'deliver'])->name('deliver');
                Route::post('{shipment}/fail', [ShipmentController::class, 'fail'])->name('fail');
            });
        });
    });
