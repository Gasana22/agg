<?php

namespace App\Models;

use App\Enums\CropSeasonStatus;
use Illuminate\Database\Eloquent\Model;

class CropSeason extends Model
{
    protected $fillable = [
        'farm_id',
        'crop_id',
        'plot_id',
        'season_name',
        'planned_planting_date',
        'actual_planting_date',
        'budget',
        'expected_yield',
        'expected_yield_unit',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'planned_planting_date' => 'date',
            'actual_planting_date' => 'date',
            'budget' => 'decimal:2',
            'expected_yield' => 'decimal:2',
            'status' => CropSeasonStatus::class,
        ];
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function crop()
    {
        return $this->belongsTo(Crop::class);
    }

    public function plot()
    {
        return $this->belongsTo(Plot::class);
    }

    public function activities()
    {
        return $this->hasMany(CropActivity::class);
    }

    public function monitoringLogs()
    {
        return $this->hasMany(CropMonitoringLog::class);
    }

    public function harvests()
    {
        return $this->hasMany(CropHarvest::class);
    }
}
