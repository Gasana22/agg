<?php

namespace App\Models;

use App\Enums\InventoryTransactionType;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    protected $fillable = [
        'farm_id',
        'name',
        'category',
        'unit',
        'reorder_level',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'reorder_level' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function transactions()
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    public function currentQuantity(): float
    {
        $stockIn = (float) $this->transactions()->where('type', InventoryTransactionType::StockIn->value)->sum('quantity');
        $stockOut = (float) $this->transactions()->where('type', InventoryTransactionType::StockOut->value)->sum('quantity');

        return $stockIn - $stockOut;
    }

    public function isLowStock(): bool
    {
        if ($this->reorder_level === null) {
            return false;
        }

        return $this->currentQuantity() <= (float) $this->reorder_level;
    }
}
