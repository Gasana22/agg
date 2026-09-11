<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CropHarvest extends Model
{
    protected $fillable = [
        'crop_season_id',
        'harvest_date',
        'quantity',
        'unit',
        'quality_grade',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'harvest_date' => 'date',
            'quantity' => 'decimal:2',
        ];
    }

    public function cropSeason()
    {
        return $this->belongsTo(CropSeason::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function sales()
    {
        return $this->hasMany(CropSale::class);
    }
}
