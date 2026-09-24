<?php

namespace App\Modules\Sales;

use App\Modules\Finance\Application\Payables;
use App\Modules\Sales\Application\Invoicing;
use App\Modules\Sales\Domain\Models\Customer;
use App\Modules\Sales\Domain\Models\CustomerInvoice;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->resolving(Payables::class, fn (Payables $payables, $app) => $payables->register('customer_invoice', $app->make(Invoicing::class)));
    }

    public function boot(): void
    {
        Route::model('customer', Customer::class);
        Route::model('customerInvoice', CustomerInvoice::class);
    }
}
