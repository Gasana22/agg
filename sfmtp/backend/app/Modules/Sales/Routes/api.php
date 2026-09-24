<?php

use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\CustomerInvoiceController;
use App\Modules\Sales\Http\Controllers\Portal\CustomerPortalController;
use App\Modules\Sales\Http\Controllers\ProductController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
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

// Products and sales orders (ADR-0003, ADR-0016).
Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}')
    ->name('farms.')
    ->whereUuid(['product', 'salesOrder'])
    ->group(function () {
        Route::get('products', [ProductController::class, 'index'])->middleware('farm.can:sales.view|sales.orders.create|sales.pricing.manage')->name('products.index');
        Route::middleware('farm.can:sales.pricing.manage')->group(function () {
            Route::post('products', [ProductController::class, 'store'])->name('products.store');
            Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');
        });

        Route::name('sales-orders.')->prefix('sales-orders')->group(function () {
            Route::middleware('farm.can:sales.view|sales.orders.create|sales.orders.approve|sales.fulfil|sales.invoice')->group(function () {
                Route::get('/', [SalesOrderController::class, 'index'])->name('index');
                Route::get('{salesOrder}', [SalesOrderController::class, 'show'])->name('show');
            });
            Route::post('/', [SalesOrderController::class, 'store'])->middleware('farm.can:sales.orders.create')->name('store');
            Route::middleware('farm.can:sales.orders.create|sales.orders.approve')->group(function () {
                Route::patch('{salesOrder}', [SalesOrderController::class, 'update'])->name('update');
                Route::post('{salesOrder}/approve', [SalesOrderController::class, 'approve'])->name('approve');
                Route::post('{salesOrder}/reject', [SalesOrderController::class, 'reject'])->name('reject');
                Route::post('{salesOrder}/cancel', [SalesOrderController::class, 'cancel'])->name('cancel');
            });
            Route::post('{salesOrder}/invoice', [SalesOrderController::class, 'invoice'])->middleware('farm.can:sales.invoice')->name('invoice');
            Route::post('{salesOrder}/dispatch', [SalesOrderController::class, 'dispatch'])->middleware('farm.can:sales.fulfil')->name('dispatch');
        });
    });

// The customer portal: a party's orders and purchases from every farm it buys from.
Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'party:customer'])
    ->prefix('customer/{party}')
    ->name('customer.')
    ->whereUuid(['party', 'farm', 'product', 'salesOrder', 'shipmentId'])
    ->group(function () {
        Route::get('dashboard', [CustomerPortalController::class, 'dashboard'])->name('dashboard');
        Route::get('products', [CustomerPortalController::class, 'products'])->name('products.index');
        Route::get('orders', [CustomerPortalController::class, 'orders'])->name('orders.index');
        Route::get('invoices', [CustomerPortalController::class, 'invoices'])->name('invoices.index');
        Route::get('deliveries', [CustomerPortalController::class, 'deliveries'])->name('deliveries.index');
        Route::get('purchases', [CustomerPortalController::class, 'purchases'])->name('purchases.index');
        Route::prefix('farms/{farm}')->name('farms.')->group(function () {
            Route::get('products/{product}/photo', [CustomerPortalController::class, 'photo'])->name('products.photo');
            Route::post('orders', [CustomerPortalController::class, 'place'])->name('orders.store');
            Route::get('orders/{salesOrder}', [CustomerPortalController::class, 'show'])->name('orders.show');
            Route::post('orders/{salesOrder}/cancel', [CustomerPortalController::class, 'cancel'])->name('orders.cancel');
            Route::post('deliveries/{shipmentId}/confirm', [CustomerPortalController::class, 'confirmDelivery'])->name('deliveries.confirm');
        });
    });
