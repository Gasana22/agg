<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plot extends Model
{
    protected $fillable = [
        'section_id',
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

    public function section()
    {
        return $this->belongsTo(Section::class);
    }
}
