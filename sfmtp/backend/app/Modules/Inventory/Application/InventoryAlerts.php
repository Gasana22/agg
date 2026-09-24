<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Low stock, out of stock and expiring lots, computed from the balances
 * (an alerts table would only duplicate them).
 */
class InventoryAlerts
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array{low_stock: array<int,array>, out_of_stock: array<int,array>, expiring: array<int,array>, expired: array<int,array>} */
    public function all(int $days = 30): array
    {
        $items = InventoryItem::where('is_active', true)->withSum('balances as on_hand', 'quantity')->orderBy('name')->get();
        $low = [];
        $out = [];
        foreach ($items as $i) {
            $onHand = (float) ($i->on_hand ?? 0);
            $row = ['item' => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'unit' => $i->unit], 'on_hand' => $onHand, 'reorder_level' => $i->reorder_level === null ? null : (float) $i->reorder_level];
            if ($onHand <= 0 && $i->reorder_level !== null) {
                $out[] = $row;
            } elseif ($i->reorder_level !== null && $onHand <= (float) $i->reorder_level) {
                $low[] = $row;
            }
        }

        $today = CarbonImmutable::now($this->context->farm()->timezone)->startOfDay();
        $lots = StockBalance::with(['item', 'lot', 'location'])->where('quantity', '>', 0)
            ->whereHas('lot', fn ($q) => $q->whereNotNull('expires_on')->whereDate('expires_on', '<=', $today->addDays($days)))
            ->get()->sortBy(fn ($b) => $b->lot->expires_on->toDateString())->values();
        $shape = fn ($b) => [
            'item' => ['id' => $b->item->id, 'code' => $b->item->code, 'name' => $b->item->name, 'unit' => $b->item->unit],
            'lot' => ['id' => $b->lot->id, 'code' => $b->lot->code, 'lot_number' => $b->lot->lot_number],
            'location' => ['id' => $b->location->id, 'code' => $b->location->code, 'name' => $b->location->name],
            'expires_on' => $b->lot->expires_on->toDateString(),
            'quantity' => (float) $b->quantity,
        ];

        return [
            'low_stock' => $low,
            'out_of_stock' => $out,
            'expiring' => $lots->filter(fn ($b) => $b->lot->expires_on->greaterThanOrEqualTo($today))->map($shape)->values()->all(),
            'expired' => $lots->filter(fn ($b) => $b->lot->expires_on->lessThan($today))->map($shape)->values()->all(),
        ];
    }
}
