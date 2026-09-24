<?php

namespace App\Modules\Procurement\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier's invoice, matched to what was received on a purchase order;
 * paid through Finance payments, or cancelled when recorded wrongly.
 *
 * @property string $status recorded | paid | cancelled
 */
class SupplierInvoice extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'invoice_number', 'supplier_id', 'order_id', 'invoice_date', 'due_on', 'amount', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'due_on' => 'date', 'amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'cancelled_at' => 'datetime', 'version' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class, 'invoice_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
