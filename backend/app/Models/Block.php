<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Block extends Model
{
    protected $fillable = [
        'farm_id',
        'name',
        'gps_lat',
        'gps_lng',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'gps_lat' => 'decimal:7',
            'gps_lng' => 'decimal:7',
            'is_active' => 'boolean',
        ];
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function sections()
    {
        return $this->hasMany(Section::class);
    }
}
