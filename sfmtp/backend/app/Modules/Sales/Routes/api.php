<?php

use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\CustomerInvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}')
    ->name('farms.')
    ->whereUuid(['customer', 'customerInvoice'])
    ->group(function () {
        Route::get('customers', [CustomerController::class, 'index'])->middleware('farm.can:customers.view|sales.invoice')->name('customers.index');
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
    });
