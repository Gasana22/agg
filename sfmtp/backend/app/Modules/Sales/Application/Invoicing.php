<?php

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Finance\Application\Accounts;
use App\Modules\Finance\Application\ChartOfAccounts;
use App\Modules\Finance\Application\CostCenters;
use App\Modules\Finance\Application\JournalLine;
use App\Modules\Finance\Application\Ledger;
use App\Modules\Finance\Application\Money;
use App\Modules\Finance\Contracts\Payable;
use App\Modules\Finance\Domain\Models\LedgerEntry;
use App\Modules\Inventory\Application\Qty;
use App\Modules\Livestock\Domain\Enums\SaleStatus;
use App\Modules\Livestock\Domain\Models\SaleRequest;
use App\Modules\Sales\Domain\Models\Customer;
use App\Modules\Sales\Domain\Models\CustomerInvoice;
use App\Support\Database\Codes;
use App\Support\Database\Sequence;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Customers and their invoices (docs/04 §3: the accountant invoices and
 * collects). A draft can change freely; issuing posts Dr receivables, Cr
 * the income account of each line (with its cost centre) and fixes the
 * invoice. A completed livestock sale is billed at most once, and its line
 * is charged to the animal, so sales show in its group's profit.
 */
class Invoicing implements Payable
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Accounts $accounts,
        private readonly CostCenters $centers,
        private readonly Ledger $ledger,
    ) {}

    // Customers

    public function createCustomer(array $data): Customer
    {
        $customer = Customer::create($data + ['code' => Codes::next(Customer::class, 'CUS')]);
        $this->audit->record('sales.customer.created', $customer, null, $customer->only(['code', 'name']));

        return $customer->refresh();
    }

    public function updateCustomer(Customer $customer, array $data): Customer
    {
        $old = $customer->only(array_keys($data));
        $customer->fill($data)->save();
        $this->audit->record('sales.customer.updated', $customer, $old, $data);

        return $customer;
    }

    // Invoices

    public function create(array $data): CustomerInvoice
    {
        $customer = $this->customer($data['customer_id'] ?? null);

        return DB::transaction(function () use ($data, $customer) {
            $invoice = CustomerInvoice::create([
                'code' => Sequence::code('customer_invoice', 'INV', 4),
                'customer_id' => $customer->id,
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'due_on' => $data['due_on'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);
            $this->writeLines($invoice, $data['lines']);
            $this->audit->record('sales.invoice.drafted', $invoice, null, $invoice->only(['code', 'amount']));

            return $invoice->refresh();
        });
    }

    public function update(CustomerInvoice $invoice, array $data): CustomerInvoice
    {
        $this->assertDraft($invoice);

        return DB::transaction(function () use ($invoice, $data) {
            if (isset($data['customer_id'])) {
                $invoice->customer_id = $this->customer($data['customer_id'])->id;
            }
            $invoice->fill(array_intersect_key($data, array_flip(['invoice_date', 'due_on', 'notes'])))->save();
            if (isset($data['lines'])) {
                $invoice->lines()->delete();
                $this->writeLines($invoice, $data['lines']);
            }
            $this->audit->record('sales.invoice.updated', $invoice, null, array_diff_key($data, ['lines' => 1]));

            return $invoice->refresh();
        });
    }

    public function issue(CustomerInvoice $invoice): CustomerInvoice
    {
        $this->assertDraft($invoice);

        return DB::transaction(function () use ($invoice) {
            $invoice = CustomerInvoice::with(['customer', 'lines.account'])->lockForUpdate()->findOrFail($invoice->id);
            if (Money::cents($invoice->amount) <= 0) {
                throw ApiException::conflict('empty_invoice', "{$invoice->code} has nothing to invoice.");
            }
            foreach ($invoice->lines as $line) {
                $this->assertSaleFree($line->animal_sale_id, $invoice->id);
            }
            $journal = [JournalLine::debit(ChartOfAccounts::RECEIVABLES, (string) $invoice->amount)];
            foreach ($invoice->lines as $line) {
                $journal[] = JournalLine::credit($line->account->code, (string) $line->amount, $line->cost_center_type, $line->cost_center_id, mb_substr($line->description, 0, 200));
            }
            $date = CarbonImmutable::parse($invoice->invoice_date);
            $entry = $this->ledger->post('customer_invoice', $invoice->id, "Invoice {$invoice->code} to {$invoice->customer->name}", $journal, $date);
            $terms = $invoice->customer->payment_terms_days;
            $invoice->forceFill([
                'status' => 'issued', 'issued_by' => Auth::id(), 'issued_at' => now(), 'ledger_entry_id' => $entry?->id,
                'due_on' => $invoice->due_on?->toDateString() ?? ($terms !== null ? $date->addDays($terms)->toDateString() : $date->toDateString()),
            ])->save();
            $this->audit->record('sales.invoice.issued', $invoice, ['status' => 'draft'], ['status' => 'issued', 'amount' => $invoice->amount]);

            return $invoice;
        });
    }

    /** A draft is dropped; an issued invoice without payments is reversed. */
    public function void(CustomerInvoice $invoice, string $reason): CustomerInvoice
    {
        if (! in_array($invoice->status, ['draft', 'issued'], true)) {
            throw ApiException::conflict('invalid_state_transition', "{$invoice->code} is {$invoice->status}.");
        }
        if (Money::cents($invoice->paid_amount) > 0) {
            throw ApiException::conflict('has_payments', "Void the payments of {$invoice->code} first.");
        }

        return DB::transaction(function () use ($invoice, $reason) {
            if ($invoice->ledger_entry_id) {
                $this->ledger->reverseDocument(LedgerEntry::findOrFail($invoice->ledger_entry_id), "Invoice {$invoice->code} voided: {$reason}");
            }
            $old = $invoice->status;
            $invoice->forceFill(['status' => 'void', 'voided_by' => Auth::id(), 'voided_at' => now(), 'void_reason' => $reason])->save();
            $this->audit->record('sales.invoice.voided', $invoice, ['status' => $old], ['status' => 'void', 'reason' => $reason]);

            return $invoice;
        });
    }

    /** Completed livestock sales with a price that no live invoice bills yet. */
    public function billableSales()
    {
        $billed = DB::table('customer_invoice_lines as l')->join('customer_invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('i.status', '!=', 'void')->whereNotNull('l.animal_sale_id')->pluck('l.animal_sale_id');

        return SaleRequest::with(['animal.group'])->where('status', SaleStatus::Completed->value)->whereNotNull('sale_price')
            ->whereNotIn('id', $billed)->orderByDesc('sold_on')->limit(100)->get();
    }

    private function writeLines(CustomerInvoice $invoice, array $lines): void
    {
        $total = 0;
        $sales = [];
        foreach ($lines as $i => $l) {
            $account = $this->accounts->ofType($l['account_id'] ?? null, ['income'], "lines.{$i}.account_id");
            $saleId = $l['animal_sale_id'] ?? null;
            $description = $l['description'] ?? null;
            [$ccType, $ccId, $ccLabel] = $this->centers->resolve($l['cost_center_type'] ?? null, $l['cost_center_id'] ?? null, "lines.{$i}.cost_center_id");
            if ($saleId) {
                if (isset($sales[$saleId])) {
                    throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.animal_sale_id" => ['The sale is already on this invoice.']]);
                }
                $sales[$saleId] = true;
                $sale = SaleRequest::with('animal')->find($saleId);
                if (! $sale || $sale->status !== SaleStatus::Completed) {
                    throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.animal_sale_id" => ['Only a completed sale can be invoiced.']]);
                }
                $this->assertSaleFree($saleId, $invoice->id, "lines.{$i}.animal_sale_id");
                $description ??= "Sale {$sale->code}: {$sale->animal->animal_code}".($sale->animal->tag_number ? " (tag {$sale->animal->tag_number})" : '');
                if ($ccType === null) {
                    [$ccType, $ccId, $ccLabel] = $this->centers->resolve('animal', $sale->animal_id);
                }
            }
            if (! $description) {
                throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.description" => ['Describe what is invoiced.']]);
            }
            $amount = (int) round(Qty::milli($l['quantity']) * Money::cents($l['unit_price']) / 1000);
            $total += $amount;
            $invoice->lines()->create([
                'position' => $i + 1,
                'description' => $description, 'quantity' => Qty::of(Qty::milli($l['quantity'])), 'unit' => $l['unit'] ?? null,
                'unit_price' => Money::of($l['unit_price']), 'amount' => Money::fromCents($amount), 'account_id' => $account->id,
                'cost_center_type' => $ccType, 'cost_center_id' => $ccId, 'cost_center_label' => $ccLabel, 'animal_sale_id' => $saleId,
            ]);
        }
        $invoice->forceFill(['amount' => Money::fromCents($total)])->save();
    }

    private function assertSaleFree(?string $saleId, string $invoiceId, string $field = 'lines'): void
    {
        if (! $saleId) {
            return;
        }
        $other = DB::table('customer_invoice_lines as l')->join('customer_invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('l.animal_sale_id', $saleId)->where('i.id', '!=', $invoiceId)->where('i.status', '!=', 'void')->value('i.code');
        if ($other) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => ["This sale is already billed on {$other}."]]);
        }
    }

    private function customer(?string $id): Customer
    {
        return ($id ? Customer::where('is_active', true)->find($id) : null)
            ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['customer_id' => ['Choose an active customer of this farm.']]);
    }

    private function assertDraft(CustomerInvoice $invoice): void
    {
        if ($invoice->status !== 'draft') {
            throw ApiException::conflict('invalid_state_transition', "{$invoice->code} is {$invoice->status}; only drafts change.");
        }
    }

    // Payable

    public function direction(): string
    {
        return 'in';
    }

    public function settlementAccount(): string
    {
        return ChartOfAccounts::RECEIVABLES;
    }

    public function permission(): string
    {
        return 'sales.invoice|finance.manage';
    }

    public function lock(string $id): ?Model
    {
        return CustomerInvoice::with('customer')->whereKey($id)->lockForUpdate()->first();
    }

    public function outstanding(Model $document): int
    {
        /** @var CustomerInvoice $document */
        return $document->status === 'issued' ? Money::cents($document->amount) - Money::cents($document->paid_amount) : 0;
    }

    public function code(Model $document): string
    {
        return $document->code;
    }

    public function party(Model $document): ?string
    {
        return $document->customer?->name;
    }

    public function apply(Model $document, int $cents): void
    {
        /** @var CustomerInvoice $document */
        $paid = Money::cents($document->paid_amount) + $cents;
        $document->forceFill(['paid_amount' => Money::fromCents($paid), 'status' => $paid >= Money::cents($document->amount) ? 'paid' : 'issued'])->save();
    }
}
