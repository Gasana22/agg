<?php

namespace App\Models;

use App\Enums\AnimalHealthLogType;
use Illuminate\Database\Eloquent\Model;

class AnimalHealthLog extends Model
{
    protected $fillable = [
        'animal_id',
        'type',
        'date',
        'value',
        'unit',
        'product_name',
        'cost',
        'next_due_date',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'value' => 'decimal:2',
            'cost' => 'decimal:2',
            'next_due_date' => 'date',
            'type' => AnimalHealthLogType::class,
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
