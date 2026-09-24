<?php

namespace App\Modules\Procurement\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Finance\Application\Money;
use App\Modules\Inventory\Application\Qty;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\PurchaseRequest;
use App\Modules\Procurement\Domain\Models\Supplier;
use App\Modules\Tenancy\Application\FarmSettings;
use App\Modules\Tenancy\TenantContext;
use App\Support\Database\Codes;
use App\Support\Database\Sequence;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Suppliers, purchase requests and purchase orders.
 *
 * - The store and crop / livestock leads request; the manager approves.
 * - Buyers (accountant, owner) raise orders; someone holding
 *   procurement.orders.approve approves them, never the order's author
 *   unless they are the owner. Above the farm's purchase order threshold
 *   only the owner approves (docs/04 §3).
 */
class Purchasing
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ProcurementAccess $access,
        private readonly FarmSettings $settings,
        private readonly TenantContext $context,
    ) {}

    // Suppliers

    public function createSupplier(array $data): Supplier
    {
        $supplier = Supplier::create($data + ['code' => Codes::next(Supplier::class, 'SUP')]);
        $this->audit->record('procurement.supplier.created', $supplier, null, $supplier->only(['code', 'name']));

        return $supplier->refresh();
    }

    public function updateSupplier(Supplier $supplier, array $data): Supplier
    {
        $old = $supplier->only(array_keys($data));
        $supplier->fill($data)->save();
        $this->audit->record('procurement.supplier.updated', $supplier, $old, $data);

        return $supplier;
    }

    // Purchase requests

    public function request(array $data): PurchaseRequest
    {
        $this->guardPrices($data['lines'], 'estimated_unit_price');

        return DB::transaction(function () use ($data) {
            $request = PurchaseRequest::create([
                'code' => Sequence::code('purchase_request', 'PR'),
                'needed_by' => $data['needed_by'] ?? null,
                'reason' => $data['reason'] ?? null,
                'requested_by' => Auth::id(),
            ]);
            foreach ($data['lines'] as $i => $line) {
                $item = isset($line['item_id']) ? $this->item($line['item_id'], "lines.{$i}.item_id") : null;
                $request->lines()->create([
                    'item_id' => $item?->id,
                    'description' => $line['description'] ?? $item?->name ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.description" => ['Name the item or choose one.']]),
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'] ?? $item?->unit ?? 'pcs',
                    'estimated_unit_price' => $line['estimated_unit_price'] ?? null,
                ]);
            }
            $this->audit->record('procurement.request.submitted', $request, null, ['code' => $request->code, 'lines' => count($data['lines'])]);

            return $request->refresh();
        });
    }

    public function decideRequest(PurchaseRequest $request, bool $approve, ?string $note): PurchaseRequest
    {
        if ($request->status !== 'submitted') {
            throw ApiException::conflict('invalid_state_transition', "{$request->code} is {$request->status}.");
        }
        if ($request->requested_by === Auth::id() && ! $this->access->isOwner()) {
            throw ApiException::forbidden('four_eyes', 'Someone else must approve your own request.');
        }
        $status = $approve ? 'approved' : 'rejected';
        $request->forceFill(['status' => $status, 'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_note' => $note])->save();
        $this->audit->record("procurement.request.{$status}", $request, ['status' => 'submitted'], ['status' => $status, 'note' => $note]);

        return $request;
    }

    public function cancelRequest(PurchaseRequest $request): PurchaseRequest
    {
        if (! in_array($request->status, ['submitted', 'approved'], true)) {
            throw ApiException::conflict('invalid_state_transition', "{$request->code} is {$request->status}.");
        }
        if ($request->requested_by !== Auth::id() && ! $this->access->can('procurement.requests.approve')) {
            throw ApiException::forbidden();
        }
        $request->forceFill(['status' => 'cancelled'])->save();

        return $request;
    }

    // Purchase orders

    public function createOrder(array $data): PurchaseOrder
    {
        $supplier = Supplier::where('is_active', true)->find($data['supplier_id']) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['supplier_id' => ['Choose an active supplier of this farm.']]);
        $request = null;
        if (! empty($data['purchase_request_id'])) {
            $request = PurchaseRequest::find($data['purchase_request_id']) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['purchase_request_id' => ['Unknown purchase request.']]);
            if ($request->status !== 'approved') {
                throw ApiException::conflict('request_not_approved', "{$request->code} is {$request->status}; order approved requests only.");
            }
        }

        return DB::transaction(function () use ($data, $supplier, $request) {
            $order = PurchaseOrder::create([
                'code' => Sequence::code('purchase_order', 'PO'),
                'supplier_id' => $supplier->id,
                'purchase_request_id' => $request?->id,
                'expected_on' => $data['expected_on'] ?? null,
                'delivery_location_id' => $data['delivery_location_id'] ?? null,
                'currency' => $this->context->farm()->currency,
                'total_amount' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);
            $this->writeLines($order, $data['lines']);
            $request?->forceFill(['status' => 'ordered'])->save();
            $this->audit->record('procurement.order.created', $order, null, ['code' => $order->code, 'supplier' => $supplier->code, 'total' => $order->total_amount]);

            return $order->refresh();
        });
    }

    public function updateOrder(PurchaseOrder $order, array $data): PurchaseOrder
    {
        if ($order->status !== 'draft') {
            throw ApiException::conflict('invalid_state_transition', "{$order->code} is {$order->status}; only drafts can change.");
        }

        return DB::transaction(function () use ($order, $data) {
            $order->fill(array_intersect_key($data, array_flip(['expected_on', 'delivery_location_id', 'notes'])))->save();
            if (isset($data['lines'])) {
                $order->lines()->delete();
                $this->writeLines($order, $data['lines']);
            }
            $this->audit->record('procurement.order.updated', $order, null, ['total' => $order->refresh()->total_amount]);

            return $order;
        });
    }

    public function approveOrder(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status !== 'draft') {
            throw ApiException::conflict('invalid_state_transition', "{$order->code} is {$order->status}.");
        }
        if ($order->created_by === Auth::id() && ! $this->access->isOwner()) {
            throw ApiException::forbidden('four_eyes', 'Someone other than the buyer must approve this order.');
        }
        $threshold = $this->settings->get($this->context->farm())['approval_thresholds']['purchase_order'] ?? null;
        if ($threshold !== null && (float) $order->total_amount > (float) $threshold && ! $this->access->isOwner()) {
            throw new ApiException(403, 'approval_required', 'Orders above '.number_format((float) $threshold).' '.$order->currency.' need the owner.', ['threshold' => (float) $threshold]);
        }
        $order->forceFill(['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now()])->save();
        $this->audit->record('procurement.order.approved', $order, ['status' => 'draft'], ['status' => 'approved', 'total' => $order->total_amount]);

        return $order;
    }

    /** Mark the order as sent to the supplier (by phone, email or the portal in Phase 12). */
    public function sendOrder(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status !== 'approved') {
            throw ApiException::conflict('invalid_state_transition', "{$order->code} is {$order->status}; approve it first.");
        }
        $order->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        $this->audit->record('procurement.order.sent', $order, ['status' => 'approved'], ['status' => 'sent']);

        return $order;
    }

    public function cancelOrder(PurchaseOrder $order, string $reason): PurchaseOrder
    {
        if (! in_array($order->status, ['draft', 'approved', 'sent'], true)) {
            throw ApiException::conflict('invalid_state_transition', "{$order->code} is {$order->status}; received orders are closed, not cancelled.");
        }
        $from = $order->status;
        $order->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason])->save();
        $this->audit->record('procurement.order.cancelled', $order, ['status' => $from], ['status' => 'cancelled', 'reason' => $reason]);

        return $order;
    }

    /** Stop expecting the rest of a partly received order. */
    public function closeOrder(PurchaseOrder $order): PurchaseOrder
    {
        if (! in_array($order->status, ['partially_received', 'received'], true)) {
            throw ApiException::conflict('invalid_state_transition', "{$order->code} is {$order->status}.");
        }
        $from = $order->status;
        $order->forceFill(['status' => 'closed'])->save();
        $this->audit->record('procurement.order.closed', $order, ['status' => $from], ['status' => 'closed']);

        return $order;
    }

    private function writeLines(PurchaseOrder $order, array $lines): void
    {
        $total = 0;
        foreach ($lines as $i => $line) {
            $item = $this->item($line['item_id'], "lines.{$i}.item_id");
            $order->lines()->create([
                'item_id' => $item->id,
                'description' => $line['description'] ?? null,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
            ]);
            $total += (int) round(Qty::milli($line['quantity']) * (float) $line['unit_price'] / 10);
        }
        $order->forceFill(['total_amount' => Money::fromCents($total)])->save();
    }

    private function guardPrices(array $lines, string $field): void
    {
        foreach ($lines as $line) {
            if (isset($line[$field]) && ! $this->access->seesPrices()) {
                throw ApiException::forbidden('money_field_forbidden', 'You cannot record prices.');
            }
        }
    }

    private function item(string $id, string $field): InventoryItem
    {
        return InventoryItem::where('is_active', true)->find($id) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => ['The selected item does not exist in this farm.']]);
    }
}
