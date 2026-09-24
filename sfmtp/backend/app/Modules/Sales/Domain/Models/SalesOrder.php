<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * requested → approved (or rejected) → invoiced → dispatched → delivered;
 * cancelled while nothing has left. Dispatch may come before the invoice
 * (cash on delivery); the invoice is then made later.
 */
class SalesOrder extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'customer_id', 'status', 'source', 'currency', 'total_amount', 'requested_delivery_on', 'delivery_address',
        'customer_note', 'internal_note', 'placed_by'];

    protected function casts(): array
    {
        return ['total_amount' => 'decimal:2', 'requested_delivery_on' => 'date', 'approved_at' => 'datetime', 'closed_at' => 'datetime', 'delivered_at' => 'datetime', 'version' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class, 'order_id')->orderBy('position');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'sales_order_id');
    }

    public function placer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
