<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CropSale extends Model
{
    protected $fillable = [
        'crop_harvest_id',
        'buyer_name',
        'quantity_sold',
        'unit_price',
        'sale_date',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity_sold' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'sale_date' => 'date',
        ];
    }

    public function cropHarvest()
    {
        return $this->belongsTo(CropHarvest::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function revenue(): float
    {
        return (float) $this->quantity_sold * (float) $this->unit_price;
    }
}
