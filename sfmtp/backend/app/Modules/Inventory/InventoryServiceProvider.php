<?php

namespace App\Modules\Inventory;

use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\InventoryRequest;
use App\Modules\Inventory\Domain\Models\StockAdjustment;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::model('item', InventoryItem::class);
        Route::model('adjustment', StockAdjustment::class);
        Route::model('inventoryRequest', InventoryRequest::class);
    }
}
