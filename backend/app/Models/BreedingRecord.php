<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BreedingRecord extends Model
{
    protected $fillable = [
        'farm_id',
        'dam_id',
        'sire_id',
        'breeding_date',
        'expected_due_date',
        'actual_birth_date',
        'offspring_count',
        'status',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'breeding_date' => 'date',
            'expected_due_date' => 'date',
            'actual_birth_date' => 'date',
        ];
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function dam()
    {
        return $this->belongsTo(Animal::class, 'dam_id');
    }

    public function sire()
    {
        return $this->belongsTo(Animal::class, 'sire_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
