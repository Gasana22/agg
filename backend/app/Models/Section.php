<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    protected $fillable = [
        'block_id',
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

    public function block()
    {
        return $this->belongsTo(Block::class);
    }

    public function plots()
    {
        return $this->hasMany(Plot::class);
    }
}
