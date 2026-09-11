<?php

namespace App\Models;

use App\Enums\CropMonitoringType;
use Illuminate\Database\Eloquent\Model;

class CropMonitoringLog extends Model
{
    protected $fillable = [
        'crop_season_id',
        'type',
        'date',
        'description',
        'severity',
        'photo_path',
        'reported_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => CropMonitoringType::class,
        ];
    }

    public function cropSeason()
    {
        return $this->belongsTo(CropSeason::class);
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
