<?php

namespace App\Models;

use App\Enums\AnimalStatus;
use Illuminate\Database\Eloquent\Model;

class Animal extends Model
{
    protected $fillable = [
        'farm_id',
        'tag_number',
        'name',
        'species',
        'breed',
        'sex',
        'birth_date',
        'dam_id',
        'sire_id',
        'source',
        'acquired_date',
        'status',
        'death_date',
        'cause_of_death',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'acquired_date' => 'date',
            'death_date' => 'date',
            'status' => AnimalStatus::class,
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

    public function healthLogs()
    {
        return $this->hasMany(AnimalHealthLog::class);
    }

    public function productionRecords()
    {
        return $this->hasMany(AnimalProductionRecord::class);
    }

    public function sales()
    {
        return $this->hasMany(AnimalSale::class);
    }
}
