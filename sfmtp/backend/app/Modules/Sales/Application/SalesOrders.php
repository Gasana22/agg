<?php

namespace App\Modules\Sales\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Finance\Application\Accounts;
use App\Modules\Finance\Application\ChartOfAccounts;
use App\Modules\Finance\Application\Money;
use App\Modules\Inventory\Application\Qty;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Notifications\Application\Inbox;
use App\Modules\Sales\Domain\Models\Customer;
use App\Modules\Sales\Domain\Models\Product;
use App\Modules\Sales\Domain\Models\SalesOrder;
use App\Modules\Sales\Domain\Models\Shipment;
use App\Modules\Tenancy\Application\FarmSettings;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Support\Database\Codes;
use App\Support\Database\Sequence;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Products and sales orders (ADR-0003):
 * - the owner (sales.pricing.manage) keeps products and list prices;
 * - customers order in the portal, staff record orders (sales.orders.create);
 * - an order is approved by staff, and above the farm's sales order
 *   threshold only by someone holding sales.orders.approve who did not
 *   place it (the owner excepted); a staff order under the threshold is
 *   approved as it is recorded;
 * - the accountant invoices it (sales.invoice), the store dispatches it
 *   (sales.fulfil) from trace batches, and delivery closes it.
 */
class SalesOrders
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Invoicing $invoicing,
        private readonly Shipments $shipments,
        private readonly FarmPermissions $permissions,
        private readonly FarmSettings $settings,
        private readonly TenantContext $context,
        private readonly Inbox $inbox,
        private readonly ChartOfAccounts $chart,
    ) {}

    // Products

    public function createProduct(array $data): Product
    {
        $data = $this->productRefs($data);
        $product = Product::create($data + [
            'code' => Codes::next(Product::class, 'PRD'),
            'currency' => $this->context->farm()->currency,
            'created_by' => Auth::id(),
        ]);
        $this->audit->record('sales.product.created', $product, null, $product->only(['code', 'name', 'list_price', 'is_published']));

        return $product->refresh();
    }

    public function updateProduct(Product $product, array $data): Product
    {
        $data = $this->productRefs($data);
        $old = $product->only(array_keys($data));
        $product->fill($data)->save();
        $this->audit->record('sales.product.updated', $product, $old, $data);

        return $product;
    }

    // Orders

    /**
     * @param  array{requested_delivery_on?:?string, delivery_address?:?string, customer_note?:?string, internal_note?:?string, lines:array<int,array{product_id:string, quantity:string|float, unit_price?:string|float|null}>}  $data
     */
    public function place(string $customerId, array $data, string $source): SalesOrder
    {
        $customer = Customer::where('is_active', true)->find($customerId)
            ?? throw $this->invalid('customer_id', 'Choose an active customer of this farm.');

        return DB::transaction(function () use ($customer, $data, $source) {
            $order = SalesOrder::create([
                'code' => Sequence::code('sales_order', 'SO', 4),
                'customer_id' => $customer->id,
                'status' => 'requested',
                'source' => $source,
                'currency' => $this->context->farm()->currency,
                'total_amount' => 0,
                'requested_delivery_on' => $data['requested_delivery_on'] ?? null,
                'delivery_address' => $data['delivery_address'] ?? $customer->address,
                'customer_note' => $data['customer_note'] ?? null,
                'internal_note' => $source === 'internal' ? ($data['internal_note'] ?? null) : null,
                'placed_by' => Auth::id(),
            ]);
            $this->writeLines($order, $data['lines'], $source);
            $this->audit->record('sales.order.placed', $order, null, ['code' => $order->code, 'customer' => $customer->code, 'total' => $order->total_amount, 'source' => $source]);

            if ($source === 'internal' && ! $this->needsHigherApproval($order)) {
                $this->markApproved($order);
            } else {
                $this->inbox->notifyHolders($this->needsHigherApproval($order) ? 'sales.orders.approve' : 'sales.orders.create|sales.orders.approve', 'sales_order',
                    "New order {$order->code} from {$customer->name}", number_format((float) $order->total_amount, 2)." {$order->currency} to approve.",
                    "/sales-orders/{$order->id}", ['order_id' => $order->id], Auth::id());
            }

            return $order->refresh();
        });
    }

    /** Staff adjust a requested order (quantities, prices) before approving it. */
    public function update(SalesOrder $order, array $data): SalesOrder
    {
        $this->assertStatus($order, ['requested'], 'only requested orders change');

        return DB::transaction(function () use ($order, $data) {
            $order->fill(array_intersect_key($data, array_flip(['requested_delivery_on', 'delivery_address', 'internal_note'])))->save();
            if (isset($data['lines'])) {
                $order->lines()->delete();
                $this->writeLines($order, $data['lines'], 'internal');
            }
            $this->audit->record('sales.order.updated', $order, null, ['total' => $order->refresh()->total_amount]);

            return $order;
        });
    }

    public function approve(SalesOrder $order): SalesOrder
    {
        $this->assertStatus($order, ['requested']);
        if ($this->needsHigherApproval($order)) {
            if (! $this->permissions->allows('sales.orders.approve')) {
                $threshold = $this->threshold();
                throw new ApiException(403, 'approval_required', 'Orders above '.number_format((float) $threshold).' '.$order->currency.' need the owner.', ['threshold' => (float) $threshold]);
            }
            if ($order->placed_by === Auth::id() && ! $this->isOwner()) {
                throw ApiException::forbidden('four_eyes', 'Someone other than whoever recorded this order must approve it.');
            }
        }

        return DB::transaction(fn () => $this->markApproved($order));
    }

    public function reject(SalesOrder $order, string $reason): SalesOrder
    {
        $this->assertStatus($order, ['requested']);
        $order->forceFill(['status' => 'rejected', 'reject_reason' => $reason, 'closed_by' => Auth::id(), 'closed_at' => now()])->save();
        $this->audit->record('sales.order.rejected', $order, ['status' => 'requested'], ['status' => 'rejected', 'reason' => $reason]);

        return $order;
    }

    /** Cancel before anything is invoiced or dispatched (by staff, or by the customer while requested). */
    public function cancel(SalesOrder $order, string $reason): SalesOrder
    {
        $this->assertStatus($order, ['requested', 'approved'], 'invoiced or dispatched orders are not cancelled');
        $from = $order->status;
        $order->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason, 'closed_by' => Auth::id(), 'closed_at' => now()])->save();
        $this->audit->record('sales.order.cancelled', $order, ['status' => $from], ['status' => 'cancelled', 'reason' => $reason]);

        return $order;
    }

    /** A draft invoice from the order, at the ordered prices; the accountant issues it as usual. */
    public function invoice(SalesOrder $order): SalesOrder
    {
        $this->assertStatus($order, ['approved', 'dispatched', 'delivered'], 'approve it first');
        $order->loadMissing(['invoice', 'lines.product']);
        if ($order->invoice && $order->invoice->status !== 'void') {
            throw ApiException::conflict('already_invoiced', "{$order->code} is already on invoice {$order->invoice->code}.");
        }

        return DB::transaction(function () use ($order) {
            $default = $this->chart->account(ChartOfAccounts::SALES)->id;
            $invoice = $this->invoicing->create([
                'customer_id' => $order->customer_id,
                'notes' => "Sales order {$order->code}",
                'lines' => $order->lines->map(fn ($l) => [
                    'description' => $l->description,
                    'quantity' => (string) $l->quantity,
                    'unit' => $l->unit,
                    'unit_price' => (string) $l->unit_price,
                    'account_id' => $l->product->income_account_id ?? $default,
                ])->all(),
            ]);
            $from = $order->status;
            $order->forceFill(['customer_invoice_id' => $invoice->id, 'status' => $from === 'approved' ? 'invoiced' : $from])->save();
            $this->audit->record('sales.order.invoiced', $order, ['status' => $from], ['status' => $order->status, 'invoice' => $invoice->code]);

            return $order;
        });
    }

    /**
     * Ship all or part of the order from trace batches (Shipments::dispatch),
     * so the journey reaches the customer.
     *
     * @param  array{destination?:?string, vehicle?:?string, driver?:?string, notes?:?string, dispatched_at?:?string, lines:array<int,array{order_line_id:string, batch_id:string, quantity:string|float}>}  $data
     */
    public function dispatch(SalesOrder $order, array $data): Shipment
    {
        $this->assertStatus($order, ['approved', 'invoiced', 'dispatched'], 'approve it first');

        return DB::transaction(function () use ($order, $data) {
            $order = SalesOrder::with(['invoice'])->lockForUpdate()->findOrFail($order->id);
            $lines = $order->lines()->lockForUpdate()->get()->keyBy('id');
            $sending = [];
            $shipLines = [];
            foreach ($data['lines'] as $i => $l) {
                $line = $lines->get($l['order_line_id']) ?? throw $this->invalid("lines.{$i}.order_line_id", 'Not a line of this order.');
                $batch = TraceBatch::find($l['batch_id']) ?? throw $this->invalid("lines.{$i}.batch_id", 'The selected batch does not exist in this farm.');
                if ($batch->unit !== null && $batch->unit !== $line->unit) {
                    throw $this->invalid("lines.{$i}.batch_id", "The batch is counted in {$batch->unit} and the order in {$line->unit}; choose a batch in {$line->unit}.");
                }
                $sending[$line->id] = ($sending[$line->id] ?? 0) + Qty::milli($l['quantity']);
                $left = Qty::milli($line->quantity) - Qty::milli($line->dispatched_quantity);
                if ($sending[$line->id] > $left) {
                    throw $this->invalid("lines.{$i}.quantity", 'More than still to send: '.Qty::of($left)." {$line->unit}.");
                }
                $shipLines[] = ['batch_id' => $batch->id, 'quantity' => (string) $l['quantity'], 'description' => $line->description, 'sales_order_line_id' => $line->id];
            }
            $shipment = $this->shipments->dispatch([
                'customer_id' => $order->customer_id,
                'customer_invoice_id' => $order->invoice && $order->invoice->status !== 'void' ? $order->invoice->id : null,
                'sales_order_id' => $order->id,
                'destination' => $data['destination'] ?? $order->delivery_address,
                'vehicle' => $data['vehicle'] ?? null,
                'driver' => $data['driver'] ?? null,
                'notes' => $data['notes'] ?? null,
                'dispatched_at' => $data['dispatched_at'] ?? null,
                'lines' => $shipLines,
            ]);
            foreach ($sending as $lineId => $milli) {
                $line = $lines[$lineId];
                $line->forceFill(['dispatched_quantity' => Qty::of(Qty::milli($line->dispatched_quantity) + $milli)])->save();
            }
            $from = $order->status;
            $order->forceFill(['status' => 'dispatched'])->save();
            $this->audit->record('sales.order.dispatched', $order, ['status' => $from], ['status' => 'dispatched', 'shipment' => $shipment->code]);

            return $shipment;
        });
    }

    private function markApproved(SalesOrder $order): SalesOrder
    {
        $order->forceFill(['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now()])->save();
        $this->audit->record('sales.order.approved', $order, ['status' => 'requested'], ['status' => 'approved', 'total' => $order->total_amount]);

        return $order;
    }

    private function writeLines(SalesOrder $order, array $lines, string $source): void
    {
        $total = 0;
        foreach (array_values($lines) as $i => $l) {
            $product = Product::where('is_active', true)->when($source === 'portal', fn ($q) => $q->where('is_published', true))->find($l['product_id'])
                ?? throw $this->invalid("lines.{$i}.product_id", 'Choose a product this farm sells.');
            if ($product->min_order_quantity !== null && Qty::milli($l['quantity']) < Qty::milli($product->min_order_quantity)) {
                throw $this->invalid("lines.{$i}.quantity", 'The minimum order is '.(float) $product->min_order_quantity." {$product->unit}.");
            }
            // Customers pay the list price; staff may agree another price.
            $price = $source === 'internal' && isset($l['unit_price']) ? (string) $l['unit_price'] : (string) $product->list_price;
            $amount = (int) round(Qty::milli($l['quantity']) * Money::cents($price) / 1000);
            $total += $amount;
            $order->lines()->create([
                'position' => $i + 1,
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => Qty::of(Qty::milli($l['quantity'])),
                'unit' => $product->unit,
                'unit_price' => Money::of($price),
                'amount' => Money::fromCents($amount),
            ]);
        }
        $order->forceFill(['total_amount' => Money::fromCents($total)])->save();
    }

    private function productRefs(array $data): array
    {
        if (! empty($data['inventory_item_id']) && ! InventoryItem::whereKey($data['inventory_item_id'])->exists()) {
            throw $this->invalid('inventory_item_id', 'The selected item does not exist in this farm.');
        }
        if (! empty($data['income_account_id'])) {
            app(Accounts::class)->ofType($data['income_account_id'], ['income'], 'income_account_id');
        }

        return $data;
    }

    private function needsHigherApproval(SalesOrder $order): bool
    {
        $threshold = $this->threshold();

        return $threshold !== null && (float) $order->total_amount > (float) $threshold;
    }

    private function threshold(): ?float
    {
        $value = $this->settings->get($this->context->farm())['approval_thresholds']['sales_order'] ?? null;

        return $value === null ? null : (float) $value;
    }

    private function isOwner(): bool
    {
        return (bool) $this->context->membership()?->is_owner;
    }

    /** @param  array<int, string>  $allowed */
    private function assertStatus(SalesOrder $order, array $allowed, ?string $hint = null): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw ApiException::conflict('invalid_state_transition', "{$order->code} is {$order->status}".($hint ? "; {$hint}." : '.'));
        }
    }

    private function invalid(string $field, string $message): ApiException
    {
        return ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => [$message]]);
    }
}
