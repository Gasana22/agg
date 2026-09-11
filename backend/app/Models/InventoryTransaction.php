<?php

namespace App\Models;

use App\Enums\InventoryTransactionType;
use Illuminate\Database\Eloquent\Model;

class InventoryTransaction extends Model
{
    protected $fillable = [
        'farm_id',
        'inventory_item_id',
        'type',
        'quantity',
        'date',
        'delivery_id',
        'reference',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => InventoryTransactionType::class,
            'quantity' => 'decimal:2',
            'date' => 'date',
        ];
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function delivery()
    {
        return $this->belongsTo(Delivery::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
