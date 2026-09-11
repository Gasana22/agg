<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PoultryMortalityLog extends Model
{
    protected $fillable = [
        'poultry_flock_id',
        'date',
        'quantity',
        'cause',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'quantity' => 'integer',
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
