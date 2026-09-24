<?php

namespace App\Modules\Procurement\Portal;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Finance\Application\Money;
use App\Modules\Inventory\Application\Qty;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Notifications\Application\Inbox;
use App\Modules\Parties\Application\PartyContext;
use App\Modules\Parties\Domain\Models\PartyLink;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\SupplierDispatch;
use App\Modules\Procurement\Domain\Models\SupplierInvoice;
use App\Modules\Procurement\Domain\Models\SupplierInvoiceSubmission;
use App\Support\Database\Sequence;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * What a supplier does in the portal (docs/04 §5), always inside one farm's
 * context and only on that farm's orders to the linked supplier record:
 * - answer a sent order (accept with quantities and a date, or reject);
 * - announce a dispatch, with the delivery note;
 * - submit an invoice for goods received and not yet invoiced;
 * - see deliveries, recorded invoices and what has been paid.
 *
 * Drafts and approved-but-unsent orders never reach the portal, and nothing
 * internal (notes, people, other suppliers, stock, lots) is shown.
 */
class SupplierPortal
{
    /** Order statuses a supplier sees. Cancelled only when it had been sent. */
    public const VISIBLE = ['sent', 'partially_received', 'received', 'closed', 'cancelled'];

    public function __construct(
        private readonly PartyContext $parties,
        private readonly AuditLogger $audit,
        private readonly Inbox $inbox,
    ) {}

    /** The linked supplier's visible orders in the current farm. */
    public function orders(PartyLink $link): Builder
    {
        return PurchaseOrder::where('supplier_id', $link->record_id)->whereIn('status', self::VISIBLE)->whereNotNull('sent_at');
    }

    public function order(PartyLink $link, string $id): PurchaseOrder
    {
        return $this->orders($link)->whereKey($id)->first() ?? throw ApiException::notFound();
    }

    /** @param  array{decision:string, note?:?string, promised_on?:?string, lines?:array<int,array{line_id:string, confirmed_quantity:string|float}>}  $data */
    public function respond(PurchaseOrder $order, array $data): PurchaseOrder
    {
        $order->loadMissing('supplier');
        if ($order->status !== 'sent') {
            throw ApiException::conflict('invalid_state_transition', "{$order->code} is {$order->status}; answer an order before goods are received.");
        }
        $accept = $data['decision'] === 'accepted';
        if (! $accept && empty($data['note'])) {
            throw $this->invalid('note', 'Say why you cannot supply this order.');
        }

        return DB::transaction(function () use ($order, $data, $accept) {
            $lines = $order->lines()->lockForUpdate()->get()->keyBy('id');
            $confirmed = [];
            foreach ($data['lines'] ?? [] as $i => $l) {
                $line = $lines->get($l['line_id']) ?? throw $this->invalid("lines.{$i}.line_id", 'Not a line of this order.');
                if (Qty::milli($l['confirmed_quantity']) > Qty::milli($line->quantity)) {
                    throw $this->invalid("lines.{$i}.confirmed_quantity", 'More than ordered ('.(float) $line->quantity.').');
                }
                $confirmed[$line->id] = $l['confirmed_quantity'];
            }
            foreach ($lines as $line) {
                // Accepting without quantities confirms the full order; rejecting clears them.
                $line->forceFill(['confirmed_quantity' => $accept ? ($confirmed[$line->id] ?? $line->quantity) : null])->save();
            }
            $before = $order->supplier_response;
            $order->forceFill([
                'supplier_response' => $data['decision'],
                'supplier_responded_at' => now(),
                'supplier_responded_by' => Auth::id(),
                'supplier_promised_on' => $accept ? ($data['promised_on'] ?? $order->expected_on?->toDateString()) : null,
                'supplier_note' => $data['note'] ?? null,
            ])->save();
            $this->audit->record("procurement.order.supplier_{$data['decision']}", $order, ['supplier_response' => $before], ['supplier_response' => $data['decision'], 'note' => $data['note'] ?? null]);
            $short = $accept && collect($confirmed)->contains(fn ($q, $id) => Qty::milli($q) < Qty::milli($lines[$id]->quantity));
            $this->inbox->notifyHolders('procurement.orders.manage', 'supplier_response',
                $accept ? "{$order->supplier->name} accepted {$order->code}".($short ? ' in part' : '') : "{$order->supplier->name} cannot supply {$order->code}",
                $data['note'] ?? null, "/purchase-orders/{$order->id}", ['order_id' => $order->id]);

            return $order->refresh();
        });
    }

    /** @param  array{dispatched_on?:?string, expected_on?:?string, reference?:?string, media_id?:?string, vehicle?:?string, driver?:?string, note?:?string, lines:array<int,array{order_line_id:string, quantity:string|float}>}  $data */
    public function dispatch(PurchaseOrder $order, array $data): SupplierDispatch
    {
        $order->loadMissing('supplier');
        if (! in_array($order->status, ['sent', 'partially_received'], true)) {
            throw ApiException::conflict('invalid_state_transition', "{$order->code} is {$order->status}.");
        }
        if ($order->supplier_response === 'rejected') {
            throw ApiException::conflict('order_rejected', "You rejected {$order->code}. Accept it before dispatching.");
        }
        $this->assertMedia($data['media_id'] ?? null);
        $on = CarbonImmutable::parse($data['dispatched_on'] ?? now()->toDateString());

        return DB::transaction(function () use ($order, $data, $on) {
            $lines = $order->lines()->with('item')->lockForUpdate()->get()->keyBy('id');
            $onTheWay = $this->onTheWay($order);
            $dispatch = SupplierDispatch::create([
                'code' => Sequence::code('supplier_dispatch', 'ASN'),
                'order_id' => $order->id,
                'status' => 'dispatched',
                'dispatched_on' => $on->toDateString(),
                'expected_on' => $data['expected_on'] ?? null,
                'reference' => $data['reference'] ?? null,
                'media_id' => $data['media_id'] ?? null,
                'vehicle' => $data['vehicle'] ?? null,
                'driver' => $data['driver'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => Auth::id(),
            ]);
            foreach ($data['lines'] as $i => $l) {
                $line = $lines->get($l['order_line_id']) ?? throw $this->invalid("lines.{$i}.order_line_id", 'Not a line of this order.');
                $left = Qty::milli($line->quantity) - Qty::milli($line->received_quantity) - ($onTheWay[$line->id] ?? 0);
                if (Qty::milli($l['quantity']) > $left) {
                    throw $this->invalid("lines.{$i}.quantity", 'More than still expected: '.Qty::of(max(0, $left))." {$line->item->unit}.");
                }
                $dispatch->lines()->create(['order_line_id' => $line->id, 'quantity' => $l['quantity']]);
            }
            $this->audit->record('procurement.dispatch.announced', $dispatch, null, ['code' => $dispatch->code, 'order' => $order->code]);
            $this->inbox->notifyHolders('procurement.deliveries.receive|procurement.orders.manage', 'supplier_dispatch',
                "{$order->supplier->name} dispatched goods for {$order->code}",
                'Expected '.($dispatch->expected_on?->toFormattedDayDateString() ?? 'soon').($dispatch->reference ? ", delivery note {$dispatch->reference}" : '').'.',
                "/purchase-orders/{$order->id}", ['order_id' => $order->id, 'dispatch_id' => $dispatch->id]);

            return $dispatch->load('lines');
        });
    }

    /** @param  array{invoice_number:string, invoice_date:string, due_on?:?string, media_id?:?string, notes?:?string, lines:array<int,array{order_line_id:string, quantity:string|float, unit_price:string|float}>}  $data */
    public function submitInvoice(PurchaseOrder $order, array $data): SupplierInvoiceSubmission
    {
        $order->loadMissing('supplier');
        if (! in_array($order->status, ['partially_received', 'received', 'closed'], true)) {
            throw ApiException::conflict('nothing_received', "Nothing has been received on {$order->code} yet; invoice what the farm has received.");
        }
        $this->assertMedia($data['media_id'] ?? null);
        $number = trim($data['invoice_number']);
        $taken = SupplierInvoice::where('supplier_id', $order->supplier_id)->where('invoice_number', $number)->where('status', '!=', 'cancelled')->exists()
            || SupplierInvoiceSubmission::where('supplier_id', $order->supplier_id)->where('invoice_number', $number)->where('status', 'submitted')->exists();
        if ($taken) {
            throw ApiException::conflict('duplicate', "Invoice {$number} has already been sent to this farm.");
        }

        return DB::transaction(function () use ($order, $data, $number) {
            $lines = $order->lines()->with('item')->lockForUpdate()->get()->keyBy('id');
            $pending = $this->pendingInvoiced($order);
            $total = 0;
            $clean = [];
            foreach ($data['lines'] as $i => $l) {
                $line = $lines->get($l['order_line_id']) ?? throw $this->invalid("lines.{$i}.order_line_id", 'Not a line of this order.');
                $open = Qty::milli($line->received_quantity) - Qty::milli($line->invoiced_quantity) - ($pending[$line->id] ?? 0);
                if (Qty::milli($l['quantity']) > $open) {
                    throw $this->invalid("lines.{$i}.quantity", 'More than received and not yet invoiced: '.Qty::of(max(0, $open))." {$line->item->unit}.");
                }
                $total += (int) round(Qty::milli($l['quantity']) * (float) $l['unit_price'] / 10);
                $clean[] = ['order_line_id' => $line->id, 'quantity' => (string) $l['quantity'], 'unit_price' => (string) $l['unit_price']];
            }
            $submission = SupplierInvoiceSubmission::create([
                'code' => Sequence::code('supplier_invoice_submission', 'SUB'),
                'order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'status' => 'submitted',
                'invoice_number' => $number,
                'invoice_date' => $data['invoice_date'],
                'due_on' => $data['due_on'] ?? null,
                'amount' => Money::fromCents($total),
                'lines' => $clean,
                'media_id' => $data['media_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'submitted_by' => Auth::id(),
            ]);
            $this->audit->record('procurement.invoice.submitted', $submission, null, ['code' => $submission->code, 'number' => $number, 'amount' => $submission->amount]);
            $this->inbox->notifyHolders('procurement.orders.manage|finance.manage', 'supplier_invoice',
                "{$order->supplier->name} sent invoice {$number} for {$order->code}",
                number_format($total / 100, 2).' '.$order->currency.' to check and record.', "/purchase-orders/{$order->id}", ['order_id' => $order->id, 'submission_id' => $submission->id]);

            return $submission;
        });
    }

    /** @return array<string, mixed> an order as the supplier sees it */
    public function present(PurchaseOrder $order, PartyLink $link, bool $detail = false): array
    {
        $order->loadMissing(['lines.item', 'deliveryLocation']);
        $onTheWay = $detail ? $this->onTheWay($order) : [];
        $data = [
            'id' => $order->id,
            'type' => 'portal_purchase_order',
            'farm' => ['id' => $link->farm->id, 'name' => $link->farm->name],
            'code' => $order->code,
            'status' => $order->status,
            'sent_at' => $order->sent_at?->toIso8601ZuluString(),
            'expected_on' => $order->expected_on?->toDateString(),
            'delivery_location' => $order->deliveryLocation?->name,
            'currency' => $order->currency,
            'total_amount' => (float) $order->total_amount,
            'supplier_response' => $order->supplier_response,
            'supplier_promised_on' => $order->supplier_promised_on?->toDateString(),
            'supplier_note' => $order->supplier_note,
            'supplier_responded_at' => $order->supplier_responded_at?->toIso8601ZuluString(),
            'cancel_reason' => $order->status === 'cancelled' ? $order->cancel_reason : null,
            'lines' => $order->lines->map(fn ($l) => [
                'id' => $l->id,
                'item' => $l->item?->name,
                'unit' => $l->item?->unit,
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
                'confirmed_quantity' => $l->confirmed_quantity === null ? null : (float) $l->confirmed_quantity,
                'received_quantity' => (float) $l->received_quantity,
                'invoiced_quantity' => (float) $l->invoiced_quantity,
            ] + ($detail ? ['on_the_way' => (float) Qty::of($onTheWay[$l->id] ?? 0)] : []))->values()->all(),
        ];
        if (! $detail) {
            return $data;
        }
        $order->loadMissing(['deliveries.lines', 'dispatches.lines', 'invoices', 'submissions']);

        return $data + [
            'deliveries' => $order->deliveries->map(fn ($d) => [
                'code' => $d->code,
                'received_on' => $d->received_on->toDateString(),
                'supplier_reference' => $d->supplier_reference,
                'lines' => $d->lines->map(fn ($dl) => ['order_line_id' => $dl->order_line_id, 'quantity' => (float) $dl->quantity])->values()->all(),
            ])->values()->all(),
            'dispatches' => $order->dispatches->sortByDesc('created_at')->map(fn (SupplierDispatch $d) => $d->toPortal())->values()->all(),
            'invoices' => $order->invoices->map(fn (SupplierInvoice $i) => $this->presentInvoice($i))->values()->all(),
            'submissions' => $order->submissions->sortByDesc('created_at')->map(fn (SupplierInvoiceSubmission $s) => $s->toPortal())->values()->all(),
        ];
    }

    /** @return array<string, mixed> a recorded invoice with its payment status */
    public function presentInvoice(SupplierInvoice $invoice): array
    {
        return [
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            'amount' => (float) $invoice->amount,
            'paid_amount' => (float) $invoice->paid_amount,
            'outstanding' => $invoice->status === 'recorded' ? Money::cents($invoice->amount) / 100 - Money::cents($invoice->paid_amount) / 100 : 0.0,
            'status' => $invoice->status,
        ];
    }

    /**
     * Counts across the supplier's farms, money per currency.
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $rows = $this->parties->eachFarm('supplier', function (PartyLink $link) {
            $orders = $this->orders($link)->get(['id', 'status', 'supplier_response', 'currency']);
            $invoices = SupplierInvoice::where('supplier_id', $link->record_id)->where('status', '!=', 'cancelled')->get(['amount', 'paid_amount', 'status']);
            $currency = $link->farm->currency;

            return [
                'new' => $orders->where('status', 'sent')->whereNull('supplier_response')->count(),
                'pending' => $orders->whereIn('status', ['sent', 'partially_received'])->where('supplier_response', '!=', 'rejected')->count(),
                'in_transit' => SupplierDispatch::whereIn('order_id', $orders->pluck('id'))->where('status', 'dispatched')->count(),
                'completed' => $orders->whereIn('status', ['received', 'closed'])->count(),
                'currency' => $currency,
                'outstanding' => $invoices->where('status', 'recorded')->sum(fn ($i) => Money::cents($i->amount) - Money::cents($i->paid_amount)),
                'paid' => $invoices->sum(fn ($i) => Money::cents($i->paid_amount)),
                'submitted' => SupplierInvoiceSubmission::where('supplier_id', $link->record_id)->where('status', 'submitted')->count(),
            ];
        });

        $money = [];
        foreach ($rows as $r) {
            $money[$r['currency']] ??= ['currency' => $r['currency'], 'outstanding_invoices' => 0, 'payments_received' => 0];
            $money[$r['currency']]['outstanding_invoices'] += $r['outstanding'];
            $money[$r['currency']]['payments_received'] += $r['paid'];
        }

        return [
            'farms' => count($rows),
            'new_orders' => array_sum(array_column($rows, 'new')),
            'pending_orders' => array_sum(array_column($rows, 'pending')),
            'deliveries_in_transit' => array_sum(array_column($rows, 'in_transit')),
            'completed_orders' => array_sum(array_column($rows, 'completed')),
            'invoices_awaiting_farm' => array_sum(array_column($rows, 'submitted')),
            'money' => array_values(array_map(fn ($m) => ['currency' => $m['currency'], 'outstanding_invoices' => $m['outstanding_invoices'] / 100, 'payments_received' => $m['payments_received'] / 100], $money)),
        ];
    }

    /** @return array<string, int> quantity (milli) announced and not yet received, per order line */
    private function onTheWay(PurchaseOrder $order): array
    {
        $out = [];
        foreach (SupplierDispatch::with('lines')->where('order_id', $order->id)->where('status', 'dispatched')->get() as $d) {
            foreach ($d->lines as $l) {
                $out[$l->order_line_id] = ($out[$l->order_line_id] ?? 0) + Qty::milli($l->quantity);
            }
        }

        return $out;
    }

    /** @return array<string, int> quantity (milli) on invoices waiting for the farm, per order line */
    private function pendingInvoiced(PurchaseOrder $order): array
    {
        $out = [];
        foreach (SupplierInvoiceSubmission::where('order_id', $order->id)->where('status', 'submitted')->get() as $s) {
            foreach ($s->lines as $l) {
                $out[$l['order_line_id']] = ($out[$l['order_line_id']] ?? 0) + Qty::milli($l['quantity']);
            }
        }

        return $out;
    }

    private function assertMedia(?string $id): void
    {
        if ($id !== null && ! Media::whereKey($id)->where('uploaded_by', Auth::id())->exists()) {
            throw $this->invalid('media_id', 'Upload the document first.');
        }
    }

    private function invalid(string $field, string $message): ApiException
    {
        return ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => [$message]]);
    }
}
