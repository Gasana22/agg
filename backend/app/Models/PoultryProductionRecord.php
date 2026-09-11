<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PoultryProductionRecord extends Model
{
    protected $fillable = [
        'poultry_flock_id',
        'date',
        'product_type',
        'quantity',
        'unit',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'quantity' => 'decimal:2',
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
