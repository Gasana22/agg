<?php

namespace App\Modules\Procurement\Domain\Models;

use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A purchase order: draft → approved → sent → partially received / received → closed, or cancelled.
 */
class PurchaseOrder extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'supplier_id', 'purchase_request_id', 'expected_on', 'delivery_location_id', 'currency', 'total_amount', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['expected_on' => 'date', 'total_amount' => 'decimal:2', 'approved_at' => 'datetime', 'sent_at' => 'datetime', 'version' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function deliveryLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'delivery_location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'order_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class, 'order_id');
    }
}
