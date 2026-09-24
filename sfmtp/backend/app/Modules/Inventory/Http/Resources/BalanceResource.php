<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Domain\Models\StockBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockBalance */
class BalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item' => Refs::item($this->item),
            'location' => Refs::location($this->location),
            'lot' => Refs::lot($this->lot),
            'quantity' => (float) $this->quantity,
            'value' => $this->when(app(InventoryAccess::class)->seesValues(), fn () => (float) $this->value),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
