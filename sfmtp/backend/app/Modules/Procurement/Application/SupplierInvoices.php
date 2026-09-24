<?php

namespace App\Modules\Procurement\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Finance\Application\ChartOfAccounts;
use App\Modules\Finance\Application\Ledger;
use App\Modules\Finance\Application\Money;
use App\Modules\Finance\Contracts\Payable;
use App\Modules\Finance\Domain\Models\LedgerEntry;
use App\Modules\Inventory\Application\Qty;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\SupplierInvoice;
use App\Support\Http\ApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Supplier invoices after they are recorded: paid through Finance payments
 * (Dr payables, Cr the money account), or cancelled when recorded wrongly,
 * which reverses the entry and makes the quantities invoiceable again.
 */
class SupplierInvoices implements Payable
{
    public function __construct(private readonly AuditLogger $audit, private readonly Ledger $ledger) {}

    public function cancel(SupplierInvoice $invoice, string $reason): SupplierInvoice
    {
        if ($invoice->status !== 'recorded') {
            throw ApiException::conflict('invalid_state_transition', "{$invoice->code} is {$invoice->status}.");
        }
        if (Money::cents($invoice->paid_amount) > 0) {
            throw ApiException::conflict('has_payments', "Void the payments of {$invoice->code} first.");
        }

        return DB::transaction(function () use ($invoice, $reason) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($invoice->order_id);
            $lines = $order->lines()->lockForUpdate()->get()->keyBy('id');
            foreach ($invoice->lines()->get() as $l) {
                $line = $lines[$l->order_line_id];
                $line->forceFill(['invoiced_quantity' => Qty::of(Qty::milli($line->invoiced_quantity) - Qty::milli($l->quantity))])->save();
            }
            if ($invoice->ledger_entry_id) {
                $this->ledger->reverseDocument(LedgerEntry::findOrFail($invoice->ledger_entry_id), "Invoice {$invoice->invoice_number} ({$invoice->code}) cancelled: {$reason}");
            }
            // An order that closed by itself when fully invoiced opens again; a short-closed one stays closed.
            $fullyReceived = $lines->every(fn ($line) => Qty::milli($line->received_quantity) >= Qty::milli($line->quantity));
            if ($order->status === 'closed' && $fullyReceived) {
                $order->forceFill(['status' => 'received'])->save();
            }
            $invoice->forceFill(['status' => 'cancelled', 'cancelled_by' => Auth::id(), 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $this->audit->record('procurement.invoice.cancelled', $invoice, ['status' => 'recorded'], ['status' => 'cancelled', 'reason' => $reason]);

            return $invoice;
        });
    }

    // Payable

    public function direction(): string
    {
        return 'out';
    }

    public function settlementAccount(): string
    {
        return ChartOfAccounts::PAYABLES;
    }

    public function permission(): string
    {
        return 'finance.manage';
    }

    public function lock(string $id): ?Model
    {
        return SupplierInvoice::with('supplier')->whereKey($id)->lockForUpdate()->first();
    }

    public function outstanding(Model $document): int
    {
        /** @var SupplierInvoice $document */
        return $document->status === 'recorded' ? Money::cents($document->amount) - Money::cents($document->paid_amount) : 0;
    }

    public function code(Model $document): string
    {
        return "{$document->code} ({$document->invoice_number})";
    }

    public function party(Model $document): ?string
    {
        return $document->supplier?->name;
    }

    public function apply(Model $document, int $cents): void
    {
        /** @var SupplierInvoice $document */
        $paid = Money::cents($document->paid_amount) + $cents;
        $document->forceFill(['paid_amount' => Money::fromCents($paid), 'status' => $paid >= Money::cents($document->amount) ? 'paid' : 'recorded'])->save();
    }
}
