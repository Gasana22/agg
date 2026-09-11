<?php

namespace App\Models;

use App\Enums\AnimalStatus;
use Illuminate\Database\Eloquent\Model;

class AnimalSale extends Model
{
    protected $fillable = [
        'animal_id',
        'buyer_name',
        'sale_price',
        'sale_date',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'sale_price' => 'decimal:2',
            'sale_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (AnimalSale $sale) {
            $sale->animal->update(['status' => AnimalStatus::Sold]);
        });
    }

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
