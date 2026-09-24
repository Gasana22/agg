<?php

namespace App\Modules\Procurement;

use App\Modules\Finance\Application\Payables;
use App\Modules\Procurement\Application\SupplierInvoices;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\PurchaseRequest;
use App\Modules\Procurement\Domain\Models\Supplier;
use App\Modules\Procurement\Domain\Models\SupplierInvoice;
use App\Modules\Procurement\Portal\SupplierPortalSubject;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ProcurementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([SupplierPortalSubject::class], 'sfmtp.portal-subjects');
        $this->app->resolving(Payables::class, fn (Payables $payables, $app) => $payables->register('supplier_invoice', $app->make(SupplierInvoices::class)));
    }

    public function boot(): void
    {
        Route::model('supplierInvoice', SupplierInvoice::class);
        Route::model('supplier', Supplier::class);
        Route::model('purchaseRequest', PurchaseRequest::class);
        Route::model('order', PurchaseOrder::class);
    }
}
