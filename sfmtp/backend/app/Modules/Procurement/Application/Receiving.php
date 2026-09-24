<?php

namespace App\Modules\Procurement\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\Finance\Application\ChartOfAccounts;
use App\Modules\Finance\Application\JournalLine;
use App\Modules\Finance\Application\Ledger;
use App\Modules\Finance\Application\Money;
use App\Modules\Inventory\Application\Qty;
use App\Modules\Inventory\Application\StockService;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Procurement\Domain\Models\Delivery;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\SupplierDispatch;
use App\Modules\Procurement\Domain\Models\SupplierInvoice;
use App\Support\Database\Sequence;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Deliveries and supplier invoices.
 *
 * - Receiving puts stock in at the order price, one lot (and input_lot trace
 *   batch) per line, and posts Dr Inventory / Cr Goods received not
 *   invoiced.
 * - An invoice is matched line by line to what was received and not yet
 *   invoiced (a three-way match: order, delivery, invoice). It clears
 *   GRNI at the order price, puts any price difference to purchase price
 *   variance, and owes the supplier the invoice total (accounts payable).
 */
class Receiving
{
    public function __construct(
        private readonly StockService $stock,
        private readonly Ledger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Receiving against a supplier's dispatch notice (`dispatch_id`) marks it
     * received and takes its delivery note unless another is given.
     *
     * @param  array{location_id:string, dispatch_id?:?string, received_on?:string, supplier_reference?:?string, media_id?:?string, note?:?string, lines:array<int,array{order_line_id:string, quantity:string|float, lot_number?:?string, expires_on?:?string}>}  $data
     */
    public function receive(PurchaseOrder $order, array $data): Delivery
    {
        if (! in_array($order->status, ['approved', 'sent', 'partially_received'], true)) {
            throw ApiException::conflict('invalid_state_transition', "{$order->code} is {$order->status}; only approved orders are received.");
        }
        Location::find($data['location_id']) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['location_id' => ['The selected store does not exist in this farm.']]);
        if (! empty($data['media_id']) && ! Media::whereKey($data['media_id'])->exists()) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['media_id' => ['Upload the delivery note photo first.']]);
        }
        $dispatch = null;
        if (! empty($data['dispatch_id'])) {
            $dispatch = SupplierDispatch::where('order_id', $order->id)->where('status', 'dispatched')->find($data['dispatch_id'])
                ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['dispatch_id' => ['Choose a dispatch of this order that has not been received.']]);
            $data['supplier_reference'] ??= $dispatch->reference;
            $data['media_id'] ??= $dispatch->media_id;
        }
        $receivedOn = CarbonImmutable::parse($data['received_on'] ?? now()->toDateString());
        if ($receivedOn->isFuture()) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['received_on' => ['The date cannot be in the future.']]);
        }

        return DB::transaction(function () use ($order, $data, $receivedOn, $dispatch) {
            $order = PurchaseOrder::with(['supplier'])->lockForUpdate()->findOrFail($order->id);
            $lines = $order->lines()->with('item')->lockForUpdate()->get()->keyBy('id');
            $delivery = Delivery::create([
                'code' => Sequence::code('delivery', 'GRN'),
                'order_id' => $order->id,
                'location_id' => $data['location_id'],
                'received_on' => $receivedOn->toDateString(),
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'media_id' => $data['media_id'] ?? null,
                'note' => $data['note'] ?? null,
                'received_by' => Auth::id(),
            ]);
            $at = $receivedOn->isToday() ? CarbonImmutable::now() : $receivedOn->setTime(12, 0);
            foreach ($data['lines'] as $i => $l) {
                $line = $lines->get($l['order_line_id']) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.order_line_id" => ['Not a line of this order.']]);
                $left = Qty::milli($line->quantity) - Qty::milli($line->received_quantity);
                if (Qty::milli($l['quantity']) > $left) {
                    throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.quantity" => ['More than ordered: '.Qty::of($left)." {$line->item->unit} still expected."]]);
                }
                $movement = $this->stock->receive($line->item, $data['location_id'], $l['quantity'], (string) $line->unit_price, 'receipt', 'delivery', $delivery->id,
                    ChartOfAccounts::GOODS_RECEIVED_NOT_INVOICED, [
                        'lot_number' => $l['lot_number'] ?? null,
                        'expires_on' => $l['expires_on'] ?? null,
                        'supplier_id' => $order->supplier_id,
                        'supplier' => $order->supplier->code,
                        'order' => $order->code,
                    ], $at, "{$delivery->code} from {$order->supplier->name}");
                $delivery->lines()->create(['order_line_id' => $line->id, 'quantity' => $l['quantity'], 'lot_id' => $movement->lot_id, 'movement_id' => $movement->id]);
                $line->forceFill(['received_quantity' => Qty::of(Qty::milli($line->received_quantity) + Qty::milli($l['quantity']))])->save();
            }
            $complete = $order->lines()->get()->every(fn ($line) => Qty::milli($line->received_quantity) >= Qty::milli($line->quantity));
            $order->forceFill(['status' => $complete ? 'received' : 'partially_received'])->save();
            $dispatch?->forceFill(['status' => 'received', 'delivery_id' => $delivery->id, 'received_at' => now()])->save();
            $this->audit->record('procurement.delivery.received', $delivery, null, ['code' => $delivery->code, 'order' => $order->code, 'order_status' => $order->status]);

            return $delivery;
        });
    }

    /** @param  array{invoice_number:string, invoice_date:string, due_on?:?string, notes?:?string, lines:array<int,array{order_line_id:string, quantity:string|float, unit_price:string|float}>}  $data */
    public function invoice(PurchaseOrder $order, array $data): SupplierInvoice
    {
        if (! in_array($order->status, ['partially_received', 'received', 'closed'], true)) {
            throw ApiException::conflict('nothing_received', "Nothing has been received on {$order->code} yet; invoices are matched to deliveries.");
        }
        if (SupplierInvoice::where('supplier_id', $order->supplier_id)->where('invoice_number', $data['invoice_number'])->exists()) {
            throw ApiException::conflict('duplicate', "Invoice {$data['invoice_number']} from this supplier is already recorded.");
        }

        return DB::transaction(function () use ($order, $data) {
            $order = PurchaseOrder::with('supplier')->lockForUpdate()->findOrFail($order->id);
            $lines = $order->lines()->with('item')->lockForUpdate()->get()->keyBy('id');
            $invoice = SupplierInvoice::create([
                'code' => Sequence::code('supplier_invoice', 'SINV'),
                'invoice_number' => $data['invoice_number'],
                'supplier_id' => $order->supplier_id,
                'order_id' => $order->id,
                'invoice_date' => $data['invoice_date'],
                'due_on' => $data['due_on'] ?? ($order->supplier->payment_terms_days ? CarbonImmutable::parse($data['invoice_date'])->addDays($order->supplier->payment_terms_days)->toDateString() : null),
                'amount' => 0,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => Auth::id(),
            ]);
            $grni = 0;
            $total = 0;
            foreach ($data['lines'] as $i => $l) {
                $line = $lines->get($l['order_line_id']) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.order_line_id" => ['Not a line of this order.']]);
                $open = Qty::milli($line->received_quantity) - Qty::milli($line->invoiced_quantity);
                $qty = Qty::milli($l['quantity']);
                if ($qty > $open) {
                    throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.quantity" => ['More than received and not yet invoiced: '.Qty::of($open)." {$line->item->unit}."]]);
                }
                $invoice->lines()->create(['order_line_id' => $line->id, 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price']]);
                $grni += (int) round($qty * (float) $line->unit_price / 10);
                $total += (int) round($qty * (float) $l['unit_price'] / 10);
                $line->forceFill(['invoiced_quantity' => Qty::of(Qty::milli($line->invoiced_quantity) + $qty)])->save();
            }
            $entry = $this->ledger->post('supplier_invoice', $invoice->id, "Invoice {$invoice->invoice_number} from {$order->supplier->name} ({$order->code})", [
                JournalLine::debit(ChartOfAccounts::GOODS_RECEIVED_NOT_INVOICED, Money::fromCents($grni)),
                JournalLine::debit(ChartOfAccounts::PRICE_VARIANCE, Money::fromCents($total - $grni)),
                JournalLine::credit(ChartOfAccounts::PAYABLES, Money::fromCents($total)),
            ], CarbonImmutable::parse($data['invoice_date']));
            $invoice->forceFill(['amount' => Money::fromCents($total), 'ledger_entry_id' => $entry?->id])->save();
            $done = $order->status === 'received' && $order->lines()->get()->every(fn ($line) => Qty::milli($line->invoiced_quantity) >= Qty::milli($line->quantity));
            if ($done) {
                $order->forceFill(['status' => 'closed'])->save();
            }
            $this->audit->record('procurement.invoice.recorded', $invoice, null, ['code' => $invoice->code, 'number' => $invoice->invoice_number, 'amount' => $invoice->amount]);

            return $invoice;
        });
    }
}
