<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Finance\Application\ChartOfAccounts;
use App\Modules\Finance\Application\Money;
use App\Modules\Inventory\Application\InventoryAlerts;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\InventoryRequest;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\PurchaseRequest;
use App\Modules\Procurement\Domain\Models\SupplierInvoice;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Stock and purchasing metrics for the store, manager, owner and accountant dashboards (docs/05 §3.6–3.7). */
class InventoryMetrics
{
    public function __construct(private readonly TenantContext $context, private readonly InventoryAlerts $alerts) {}

    public function items(): int
    {
        return InventoryItem::where('is_active', true)->count();
    }

    /** @return array{low:int, out:int} */
    public function lowAndOut(): array
    {
        $a = $this->context->remember('reporting.inventory_alerts', fn () => $this->alerts->all());

        return ['low' => count($a['low_stock']), 'out' => count($a['out_of_stock'])];
    }

    public function stockValue(): string
    {
        return number_format((float) StockBalance::sum('value'), 2, '.', '');
    }

    /** Movements recorded today: 'in' counts receipts, 'out' counts issues. */
    public function movedToday(string $direction): int
    {
        [$from, $to] = $this->today();

        return StockMovement::whereIn('type', $direction === 'in' ? ['receipt', 'opening'] : ['issue'])->whereBetween('occurred_at', [$from, $to])->count();
    }

    public function requestsPending(): int
    {
        return InventoryRequest::whereIn('status', ['requested', 'approved', 'partially_issued'])->count();
    }

    public function deliveriesExpected(): int
    {
        return PurchaseOrder::whereIn('status', ['approved', 'sent', 'partially_received'])->count();
    }

    public function receivedNotInvoiced(): string
    {
        return $this->accountBalance(ChartOfAccounts::GOODS_RECEIVED_NOT_INVOICED, credit: true);
    }

    // Widgets

    public function pendingRequests(): array
    {
        return InventoryRequest::with(['lines.item', 'requester'])->whereIn('status', ['requested', 'approved', 'partially_issued'])
            ->orderByRaw("CASE status WHEN 'approved' THEN 0 WHEN 'partially_issued' THEN 1 ELSE 2 END")->orderBy('created_at')->limit(10)->get()
            ->map(fn (InventoryRequest $r) => [
                'id' => $r->id,
                'title' => "{$r->code} for {$r->subject_label}",
                'subtitle' => $r->lines->map(fn ($l) => self::qty($l->quantity)." {$l->item->unit} {$l->item->name}")->implode(', ').' · '.($r->requester?->name ?? ''),
                'at' => $r->created_at?->toIso8601ZuluString(),
                'badge' => match ($r->status) {
                    'approved' => ['label' => 'To issue', 'tone' => 'warning'],
                    'partially_issued' => ['label' => 'Part issued', 'tone' => 'info'],
                    default => ['label' => 'To approve', 'tone' => 'neutral'],
                },
                'href' => "/farms/{$r->farm_id}/inventory?tab=requests",
            ])->values()->all();
    }

    public function deliveriesToReceive(): array
    {
        return PurchaseOrder::with(['supplier', 'lines.item'])->whereIn('status', ['approved', 'sent', 'partially_received'])
            ->orderByRaw('expected_on IS NULL')->orderBy('expected_on')->limit(10)->get()
            ->map(fn (PurchaseOrder $o) => [
                'id' => $o->id,
                'title' => "{$o->code} from {$o->supplier->name}",
                'subtitle' => $o->lines->map(fn ($l) => $l->item->name)->implode(', '),
                'at' => $o->expected_on ? $o->expected_on->toDateString().'T12:00:00Z' : null,
                'badge' => $o->status === 'partially_received' ? ['label' => 'Part received', 'tone' => 'info'] : ['label' => 'Expected', 'tone' => 'neutral'],
                'href' => "/farms/{$o->farm_id}/procurement/orders/{$o->id}",
            ])->values()->all();
    }

    public function expiringLots(): array
    {
        $a = $this->context->remember('reporting.inventory_alerts', fn () => $this->alerts->all());

        return collect([...$a['expired'], ...$a['expiring']])->take(10)->map(fn ($r) => [
            'id' => $r['lot']['id'].$r['location']['id'],
            'title' => "{$r['item']['name']} · {$r['lot']['code']}".($r['lot']['lot_number'] ? " ({$r['lot']['lot_number']})" : ''),
            'subtitle' => "{$r['quantity']} {$r['item']['unit']} in {$r['location']['name']}",
            'at' => $r['expires_on'].'T12:00:00Z',
            'badge' => $r['expires_on'] < CarbonImmutable::now($this->context->farm()->timezone)->toDateString() ? ['label' => 'Expired', 'tone' => 'danger'] : ['label' => 'Expires', 'tone' => 'warning'],
            'href' => "/farms/{$this->context->farmId()}/inventory/items/{$r['item']['id']}",
        ])->values()->all();
    }

    public function lowStock(): array
    {
        $a = $this->context->remember('reporting.inventory_alerts', fn () => $this->alerts->all());

        return collect([...$a['out_of_stock'], ...$a['low_stock']])->take(10)->map(fn ($r) => [
            'id' => $r['item']['id'],
            'title' => $r['item']['name'],
            'subtitle' => "{$r['on_hand']} {$r['item']['unit']} on hand, reorder at {$r['reorder_level']}",
            'at' => null,
            'badge' => $r['on_hand'] <= 0 ? ['label' => 'Out', 'tone' => 'danger'] : ['label' => 'Low', 'tone' => 'warning'],
            'href' => "/farms/{$this->context->farmId()}/inventory/items/{$r['item']['id']}",
        ])->values()->all();
    }

    public function recentMovements(): array
    {
        return StockMovement::with(['item', 'location'])->orderByDesc('occurred_at')->orderByDesc('created_at')->limit(10)->get()
            ->map(fn (StockMovement $m) => [
                'id' => $m->id,
                'title' => ucfirst(str_replace('_', ' ', $m->type)).': '.self::qty(abs((float) $m->quantity))." {$m->item->unit} {$m->item->name}",
                'subtitle' => $m->location->name.($m->note ? " · {$m->note}" : ''),
                'at' => $m->occurred_at->toIso8601ZuluString(),
                'href' => "/farms/{$m->farm_id}/inventory/items/{$m->item_id}",
            ])->values()->all();
    }

    public function purchaseRequestsToApprove(): array
    {
        return PurchaseRequest::with(['lines', 'requester'])->where('status', 'submitted')->orderBy('created_at')->limit(10)->get()
            ->map(fn (PurchaseRequest $r) => [
                'id' => $r->id,
                'title' => "{$r->code}: ".$r->lines->map(fn ($l) => $l->description)->implode(', '),
                'subtitle' => ($r->requester?->name ?? '').($r->needed_by ? " · needed by {$r->needed_by->toDateString()}" : ''),
                'at' => $r->created_at?->toIso8601ZuluString(),
                'badge' => ['label' => 'To approve', 'tone' => 'warning'],
                'href' => "/farms/{$r->farm_id}/procurement?tab=requests",
            ])->values()->all();
    }

    public function ordersToApprove(): array
    {
        return PurchaseOrder::with('supplier')->where('status', 'draft')->orderBy('created_at')->limit(10)->get()
            ->map(fn (PurchaseOrder $o) => [
                'id' => $o->id,
                'title' => "{$o->code} · {$o->supplier->name}",
                'subtitle' => number_format((float) $o->total_amount).' '.$o->currency,
                'at' => $o->created_at?->toIso8601ZuluString(),
                'badge' => ['label' => 'To approve', 'tone' => 'warning'],
                'href' => "/farms/{$o->farm_id}/procurement/orders/{$o->id}",
            ])->values()->all();
    }

    public function invoicesDue(): array
    {
        return SupplierInvoice::with('supplier')->where('status', 'recorded')->orderByRaw('due_on IS NULL')->orderBy('due_on')->limit(10)->get()
            ->map(fn (SupplierInvoice $i) => [
                'id' => $i->id,
                'title' => "{$i->supplier->name} · {$i->invoice_number}",
                'subtitle' => number_format((float) $i->amount - (float) $i->paid_amount).' '.$this->context->farm()->currency.((float) $i->paid_amount > 0 ? ' still to pay' : ''),
                'at' => $i->due_on ? $i->due_on->toDateString().'T12:00:00Z' : null,
                'badge' => $i->due_on && $i->due_on->isPast() ? ['label' => 'Overdue', 'tone' => 'danger'] : ['label' => 'Due', 'tone' => 'neutral'],
                'href' => "/farms/{$i->farm_id}/procurement/orders/{$i->order_id}",
            ])->values()->all();
    }

    /** @return array{labels: array<int,string>, values: array<int,float>} stock value by category */
    public function valueByCategory(): array
    {
        $rows = StockBalance::query()->join('inventory_items as i', 'i.id', '=', 'stock_balances.item_id')
            ->join('global_inventory_categories as c', 'c.id', '=', 'i.category_id')
            ->groupBy('c.name')->orderBy('c.name')
            ->select('c.name', DB::raw('SUM(stock_balances.value) AS v'))->get();

        return ['labels' => $rows->pluck('name')->all(), 'values' => $rows->map(fn ($r) => round((float) $r->v, 2))->all()];
    }

    private function accountBalance(string $code, bool $credit): string
    {
        $row = DB::table('ledger_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.farm_id', $this->context->farmId())->where('a.code', $code)
            ->selectRaw('COALESCE(SUM(l.debit), 0) AS d, COALESCE(SUM(l.credit), 0) AS c')->first();

        return Money::fromCents($credit ? Money::cents($row->c) - Money::cents($row->d) : Money::cents($row->d) - Money::cents($row->c));
    }

    /** 500 → "500", 0.500 → "0.5": up to three decimals, trailing zeros dropped. */
    private static function qty(string|float $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function today(): array
    {
        $start = CarbonImmutable::now($this->context->farm()->timezone)->startOfDay();

        return [$start->utc(), $start->endOfDay()->utc()];
    }
}
