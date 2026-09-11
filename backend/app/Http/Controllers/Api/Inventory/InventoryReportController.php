<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryItemResource;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryReportController extends Controller
{
    /**
     * Items with a reorder_level set whose current stock has fallen to or
     * below it. Items with no reorder_level are never "low" — there is
     * nothing to compare against.
     */
    public function lowStock(Request $request, Farm $farm): AnonymousResourceCollection
    {
        abort_unless($request->user()->canViewFarm($farm), 403);

        $lowStockItems = $farm->inventoryItems()
            ->whereNotNull('reorder_level')
            ->get()
            ->filter(fn ($item) => $item->isLowStock())
            ->values();

        return InventoryItemResource::collection($lowStockItems);
    }
}
