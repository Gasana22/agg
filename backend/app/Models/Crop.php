<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Crop extends Model
{
    protected $fillable = [
        'farm_id',
        'name',
        'variety',
        'category',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function seasons()
    {
        return $this->hasMany(CropSeason::class);
    }
}
