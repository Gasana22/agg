<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'farm_id',
        'supplier_id',
        'order_date',
        'expected_delivery_date',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'status' => PurchaseOrderStatus::class,
        ];
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function totalAmount(): float
    {
        return (float) $this->items->sum(fn (PurchaseOrderItem $item) => $item->lineTotal());
    }

    public function totalPaid(): float
    {
        return (float) $this->payments->sum('amount');
    }

    public function balance(): float
    {
        return $this->totalAmount() - $this->totalPaid();
    }
}
