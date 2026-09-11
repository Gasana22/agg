<?php

namespace App\Models;

use App\Enums\CropActivityType;
use Illuminate\Database\Eloquent\Model;

class CropActivity extends Model
{
    protected $fillable = [
        'crop_season_id',
        'type',
        'date',
        'cost',
        'gps_lat',
        'gps_lng',
        'photo_path',
        'notes',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'cost' => 'decimal:2',
            'gps_lat' => 'decimal:7',
            'gps_lng' => 'decimal:7',
            'type' => CropActivityType::class,
        ];
    }

    public function cropSeason()
    {
        return $this->belongsTo(CropSeason::class);
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
