<?php

namespace App\Modules\Procurement;

use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\PurchaseRequest;
use App\Modules\Procurement\Domain\Models\Supplier;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ProcurementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::model('supplier', Supplier::class);
        Route::model('purchaseRequest', PurchaseRequest::class);
        Route::model('order', PurchaseOrder::class);
    }
}
