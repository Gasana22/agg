<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PoultrySale extends Model
{
    protected $fillable = [
        'poultry_flock_id',
        'quantity',
        'buyer_name',
        'sale_price',
        'sale_date',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sale_price' => 'decimal:2',
            'sale_date' => 'date',
        ];
    }

    public function flock()
    {
        return $this->belongsTo(PoultryFlock::class, 'poultry_flock_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
