<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnimalProductionRecord extends Model
{
    protected $fillable = [
        'animal_id',
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

    public function animal()
    {
        return $this->belongsTo(Animal::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
