<?php

namespace App\Modules\Procurement\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invoice the supplier sent through the portal: submitted → recorded
 * (as a supplier invoice, by the farm) or rejected with a reason.
 */
class SupplierInvoiceSubmission extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'order_id', 'supplier_id', 'status', 'invoice_number', 'invoice_date', 'due_on', 'amount', 'lines', 'media_id', 'notes', 'submitted_by'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'due_on' => 'date', 'amount' => 'decimal:2', 'lines' => 'array', 'reviewed_at' => 'datetime', 'version' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return array<string, mixed> */
    public function toPortal(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date->toDateString(),
            'due_on' => $this->due_on?->toDateString(),
            'amount' => (float) $this->amount,
            'reject_reason' => $this->reject_reason,
            'submitted_at' => $this->created_at?->toIso8601ZuluString(),
            'reviewed_at' => $this->reviewed_at?->toIso8601ZuluString(),
            // Keys in a stable order (JSON columns reorder them on MySQL).
            'lines' => array_map(fn ($l) => ['order_line_id' => $l['order_line_id'], 'quantity' => (float) $l['quantity'], 'unit_price' => (float) $l['unit_price']], $this->lines ?? []),
        ];
    }
}
