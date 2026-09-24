<?php

namespace App\Modules\Procurement\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Procurement\Domain\Models\SupplierInvoiceSubmission;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Invoices suppliers sent through the portal. Recording one runs the usual
 * three-way match (Receiving::invoice), so it posts to the ledger exactly
 * like an invoice keyed in by the farm; rejecting sends it back with a
 * reason the supplier sees.
 */
class InvoiceSubmissions
{
    public function __construct(private readonly Receiving $receiving, private readonly AuditLogger $audit) {}

    /** @param  array{invoice_date?:string, due_on?:?string, notes?:?string}  $overrides */
    public function record(SupplierInvoiceSubmission $submission, array $overrides = []): SupplierInvoiceSubmission
    {
        $this->assertSubmitted($submission);

        return DB::transaction(function () use ($submission, $overrides) {
            $submission = SupplierInvoiceSubmission::with('order')->lockForUpdate()->findOrFail($submission->id);
            $this->assertSubmitted($submission);
            $invoice = $this->receiving->invoice($submission->order, [
                'invoice_number' => $submission->invoice_number,
                'invoice_date' => $overrides['invoice_date'] ?? $submission->invoice_date->toDateString(),
                'due_on' => array_key_exists('due_on', $overrides) ? $overrides['due_on'] : $submission->due_on?->toDateString(),
                'notes' => $overrides['notes'] ?? $submission->notes,
                'lines' => $submission->lines,
            ]);
            $submission->forceFill(['status' => 'recorded', 'supplier_invoice_id' => $invoice->id, 'reviewed_by' => Auth::id(), 'reviewed_at' => now()])->save();
            $this->audit->record('procurement.submission.recorded', $submission, ['status' => 'submitted'], ['status' => 'recorded', 'invoice' => $invoice->code]);

            return $submission;
        });
    }

    public function reject(SupplierInvoiceSubmission $submission, string $reason): SupplierInvoiceSubmission
    {
        $this->assertSubmitted($submission);
        $submission->forceFill(['status' => 'rejected', 'reject_reason' => $reason, 'reviewed_by' => Auth::id(), 'reviewed_at' => now()])->save();
        $this->audit->record('procurement.submission.rejected', $submission, ['status' => 'submitted'], ['status' => 'rejected', 'reason' => $reason]);

        return $submission;
    }

    private function assertSubmitted(SupplierInvoiceSubmission $submission): void
    {
        if ($submission->status !== 'submitted') {
            throw ApiException::conflict('invalid_state_transition', "{$submission->code} is already {$submission->status}.");
        }
    }
}
