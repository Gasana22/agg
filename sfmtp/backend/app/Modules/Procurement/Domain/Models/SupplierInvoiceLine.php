<?php

namespace App\Modules\Procurement\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One invoiced line: quantity and price for a purchase order line.
 */
class SupplierInvoiceLine extends Model
{
    use BelongsToFarm, HasUuids, Immutable;

    public $timestamps = false;

    protected $fillable = ['farm_id', 'invoice_id', 'order_line_id', 'quantity', 'unit_price'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:4'];
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'order_line_id');
    }
}
