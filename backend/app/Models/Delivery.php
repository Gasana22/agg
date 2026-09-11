<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'delivery_date',
        'is_complete',
        'notes',
        'received_by',
    ];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'is_complete' => 'boolean',
        ];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function inventoryTransactions()
    {
        return $this->hasMany(InventoryTransaction::class);
    }
}
