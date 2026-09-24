<?php

namespace App\Modules\Sales\Portal;

use App\Modules\Finance\Application\Money;
use App\Modules\Parties\Application\PartyContext;
use App\Modules\Parties\Domain\Models\PartyLink;
use App\Modules\Sales\Application\SalesOrders;
use App\Modules\Sales\Application\Shipments;
use App\Modules\Sales\Domain\Models\CustomerInvoice;
use App\Modules\Sales\Domain\Models\Product;
use App\Modules\Sales\Domain\Models\SalesOrder;
use App\Modules\Sales\Domain\Models\Shipment;
use App\Modules\Sales\Domain\Models\ShipmentLine;
use App\Modules\Traceability\Application\PublicPayload;
use App\Modules\Traceability\Application\Publishing;
use App\Modules\Traceability\Domain\Enums\BatchStatus;
use App\Modules\Traceability\Domain\Models\TraceQrCode;
use App\Support\Http\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * What a customer does in the portal (docs/04 §5), inside one farm's context
 * and only for the linked customer record:
 * - browse the farm's published products and order at list price;
 * - follow orders, invoices, payments and deliveries, and confirm a delivery;
 * - see the approved public traceability of the batches they bought.
 *
 * Costs, margins, stock, workers, internal notes and unapproved
 * traceability never leave the farm.
 */
class CustomerPortal
{
    public function __construct(
        private readonly PartyContext $parties,
        private readonly SalesOrders $orders,
        private readonly Shipments $shipments,
        private readonly Publishing $publishing,
    ) {}

    /** @return array<int, array<string, mixed>> published products of every linked farm */
    public function products(?string $farmId = null): array
    {
        return array_merge(...$this->parties->eachFarm('customer', fn (PartyLink $link) => $farmId !== null && $link->farm_id !== $farmId ? [] :
            Product::where('is_published', true)->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Product $p) => $this->presentProduct($p, $link))->all()) ?: [[]]);
    }

    public function product(string $id): Product
    {
        return Product::where('is_published', true)->where('is_active', true)->find($id) ?? throw ApiException::notFound();
    }

    /** @return array<string, mixed> */
    public function presentProduct(Product $p, PartyLink $link): array
    {
        return [
            'id' => $p->id,
            'type' => 'portal_product',
            'farm' => ['id' => $link->farm->id, 'name' => $link->farm->name],
            'code' => $p->code,
            'name' => $p->name,
            'description' => $p->description,
            'category' => $p->category,
            'unit' => $p->unit,
            'price' => (float) $p->list_price,
            'currency' => $p->currency,
            'min_order_quantity' => $p->min_order_quantity === null ? null : (float) $p->min_order_quantity,
            'availability_note' => $p->availability_note,
            'has_photo' => $p->media_id !== null,
        ];
    }

    public function orders(PartyLink $link): Builder
    {
        return SalesOrder::where('customer_id', $link->record_id);
    }

    public function order(PartyLink $link, string $id): SalesOrder
    {
        return $this->orders($link)->whereKey($id)->first() ?? throw ApiException::notFound();
    }

    public function place(PartyLink $link, array $data): SalesOrder
    {
        return $this->orders->place($link->record_id, [
            'requested_delivery_on' => $data['requested_delivery_on'] ?? null,
            'delivery_address' => $data['delivery_address'] ?? null,
            'customer_note' => $data['note'] ?? null,
            'lines' => array_map(fn ($l) => ['product_id' => $l['product_id'], 'quantity' => $l['quantity']], $data['lines']),
        ], 'portal');
    }

    /** A customer can withdraw an order the farm has not approved yet. */
    public function cancel(SalesOrder $order, string $reason): SalesOrder
    {
        if ($order->status !== 'requested') {
            throw ApiException::conflict('invalid_state_transition', "{$order->code} is {$order->status}; ask the farm to cancel it.");
        }

        return $this->orders->cancel($order, $reason);
    }

    public function confirmDelivery(PartyLink $link, string $shipmentId, array $data): Shipment
    {
        $shipment = Shipment::where('customer_id', $link->record_id)->find($shipmentId) ?? throw ApiException::notFound();

        return $this->shipments->deliver($shipment, [
            'received_by' => $data['received_by'] ?? Auth::user()?->name,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /** @return array<string, mixed> an order as the customer sees it */
    public function presentOrder(SalesOrder $o, PartyLink $link, bool $detail = false): array
    {
        $o->loadMissing(['lines', 'invoice', 'shipments.lines.batch']);
        $invoice = $o->invoice && $o->invoice->status !== 'draft' ? $o->invoice : null;
        $data = [
            'id' => $o->id,
            'type' => 'portal_sales_order',
            'farm' => ['id' => $link->farm->id, 'name' => $link->farm->name],
            'code' => $o->code,
            'status' => $o->status,
            'currency' => $o->currency,
            'total_amount' => (float) $o->total_amount,
            'requested_delivery_on' => $o->requested_delivery_on?->toDateString(),
            'delivery_address' => $o->delivery_address,
            'note' => $o->customer_note,
            'reject_reason' => $o->reject_reason,
            'cancel_reason' => $o->cancel_reason,
            'placed_at' => $o->created_at?->toIso8601ZuluString(),
            'lines' => $o->lines->map(fn ($l) => [
                'id' => $l->id,
                'product_id' => $l->product_id,
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'unit' => $l->unit,
                'unit_price' => (float) $l->unit_price,
                'amount' => (float) $l->amount,
                'dispatched_quantity' => (float) $l->dispatched_quantity,
            ])->values()->all(),
            'invoice' => $invoice ? $this->presentInvoice($invoice) : null,
        ];
        if (! $detail) {
            return $data;
        }

        return $data + [
            'shipments' => $o->shipments->sortBy('dispatched_at')->map(fn (Shipment $s) => $this->presentShipment($s, $link))->values()->all(),
            'timeline' => $this->timeline($o, $invoice),
        ];
    }

    /** @return array<string, mixed> */
    public function presentInvoice(CustomerInvoice $i): array
    {
        return [
            'id' => $i->id,
            'code' => $i->code,
            'status' => $i->status,
            'invoice_date' => $i->invoice_date?->toDateString(),
            'due_on' => $i->due_on?->toDateString(),
            'amount' => (float) $i->amount,
            'paid_amount' => (float) $i->paid_amount,
            'outstanding' => $i->status === 'issued' ? (Money::cents($i->amount) - Money::cents($i->paid_amount)) / 100 : 0.0,
        ];
    }

    /** @return array<string, mixed> */
    public function presentShipment(Shipment $s, PartyLink $link): array
    {
        $s->loadMissing('lines.batch');

        return [
            'id' => $s->id,
            'type' => 'portal_shipment',
            'farm' => ['id' => $link->farm->id, 'name' => $link->farm->name],
            'code' => $s->code,
            'status' => $s->status,
            'sales_order_id' => $s->sales_order_id,
            'destination' => $s->destination,
            'vehicle' => $s->vehicle,
            'dispatched_at' => $s->dispatched_at?->toIso8601ZuluString(),
            'delivered_at' => $s->delivered_at?->toIso8601ZuluString(),
            'received_by' => $s->received_by,
            'failure_reason' => $s->failure_reason,
            'lines' => $s->lines->map(fn (ShipmentLine $l) => [
                'description' => $l->description,
                'quantity' => $l->quantity === null ? null : (float) $l->quantity,
                'unit' => $l->unit,
                'batch_code' => $l->batch?->batch_code,
            ])->values()->all(),
        ];
    }

    /**
     * The batches this customer received, with what the farm approved for
     * the public (the same fields a QR scan shows) and the QR code if any.
     *
     * @return array<int, array<string, mixed>>
     */
    public function purchases(PartyLink $link): array
    {
        $shipments = Shipment::with('lines.batch')->where('customer_id', $link->record_id)->whereIn('status', ['dispatched', 'delivered'])
            ->orderByDesc('dispatched_at')->limit(200)->get();
        $rows = [];
        foreach ($shipments as $s) {
            foreach ($s->lines as $l) {
                $batch = $l->batch;
                if ($batch === null) {
                    continue;
                }
                $approval = $this->publishing->latestApproval($batch);
                $qr = TraceQrCode::where('batch_id', $batch->id)->where('status', 'active')->orderByDesc('issued_at')->first();
                $recalled = $batch->status === BatchStatus::Recalled;
                $rows[] = [
                    'type' => 'portal_purchase',
                    'farm' => ['id' => $link->farm->id, 'name' => $link->farm->name],
                    'shipment' => ['id' => $s->id, 'code' => $s->code, 'status' => $s->status, 'dispatched_at' => $s->dispatched_at?->toIso8601ZuluString()],
                    'description' => $l->description,
                    'quantity' => $l->quantity === null ? null : (float) $l->quantity,
                    'unit' => $l->unit,
                    'batch_code' => $batch->batch_code,
                    'recalled' => $recalled,
                    'qr_code' => $recalled ? null : $qr?->code,
                    // Only what the farm approved for the public; nothing when it approved nothing.
                    'public' => $approval ? (object) $this->publicFields($approval->payload ?? [], $recalled) : null,
                ];
            }
        }

        return $rows;
    }

    /** The approved fields in catalogue order (JSON columns do not keep key order); a recall keeps only what names the product. */
    private function publicFields(array $payload, bool $recalled): array
    {
        $ordered = array_intersect_key(array_replace(array_fill_keys(array_keys(PublicPayload::FIELDS), null), $payload), $payload);

        return $recalled ? array_intersect_key($ordered, array_flip(['product', 'batch_code', 'farm'])) : $ordered;
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $rows = $this->parties->eachFarm('customer', function (PartyLink $link) {
            $orders = $this->orders($link)->get(['status']);
            $invoices = CustomerInvoice::where('customer_id', $link->record_id)->whereIn('status', ['issued', 'paid'])->get(['amount', 'paid_amount', 'status']);

            return [
                'products' => Product::where('is_published', true)->where('is_active', true)->count(),
                'active' => $orders->whereIn('status', ['requested', 'approved', 'invoiced', 'dispatched'])->count(),
                'delivered' => $orders->where('status', 'delivered')->count(),
                'currency' => $link->farm->currency,
                'outstanding' => $invoices->where('status', 'issued')->sum(fn ($i) => Money::cents($i->amount) - Money::cents($i->paid_amount)),
                'purchased' => $invoices->sum(fn ($i) => Money::cents($i->amount)),
                'to_confirm' => Shipment::where('customer_id', $link->record_id)->where('status', 'dispatched')->count(),
            ];
        });
        $money = [];
        foreach ($rows as $r) {
            $money[$r['currency']] ??= ['currency' => $r['currency'], 'pending_payments' => 0, 'total_purchases' => 0];
            $money[$r['currency']]['pending_payments'] += $r['outstanding'];
            $money[$r['currency']]['total_purchases'] += $r['purchased'];
        }

        return [
            'farms' => count($rows),
            'available_products' => array_sum(array_column($rows, 'products')),
            'active_orders' => array_sum(array_column($rows, 'active')),
            'delivered_orders' => array_sum(array_column($rows, 'delivered')),
            'deliveries_to_confirm' => array_sum(array_column($rows, 'to_confirm')),
            'money' => array_values(array_map(fn ($m) => ['currency' => $m['currency'], 'pending_payments' => $m['pending_payments'] / 100, 'total_purchases' => $m['total_purchases'] / 100], $money)),
        ];
    }

    /** @return array<int, array{event:string, at:?string, detail:?string}> */
    private function timeline(SalesOrder $o, ?CustomerInvoice $invoice): array
    {
        $events = [['event' => 'placed', 'at' => $o->created_at?->toIso8601ZuluString(), 'detail' => null]];
        if ($o->approved_at) {
            $events[] = ['event' => 'approved', 'at' => $o->approved_at->toIso8601ZuluString(), 'detail' => null];
        }
        if ($o->status === 'rejected' || $o->status === 'cancelled') {
            $events[] = ['event' => $o->status, 'at' => $o->closed_at?->toIso8601ZuluString(), 'detail' => $o->reject_reason ?? $o->cancel_reason];
        }
        if ($invoice) {
            $events[] = ['event' => 'invoiced', 'at' => $invoice->issued_at?->toIso8601ZuluString(), 'detail' => $invoice->code];
        }
        foreach ($o->shipments as $s) {
            $events[] = ['event' => 'dispatched', 'at' => $s->dispatched_at?->toIso8601ZuluString(), 'detail' => $s->code];
            if ($s->status === 'delivered') {
                $events[] = ['event' => 'delivered', 'at' => $s->delivered_at?->toIso8601ZuluString(), 'detail' => $s->code];
            } elseif ($s->status === 'failed') {
                $events[] = ['event' => 'delivery_failed', 'at' => $s->updated_at?->toIso8601ZuluString(), 'detail' => $s->code];
            }
        }
        usort($events, fn ($a, $b) => strcmp($a['at'] ?? '', $b['at'] ?? ''));

        return $events;
    }
}
