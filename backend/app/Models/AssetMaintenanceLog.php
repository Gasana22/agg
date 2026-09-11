<?php

namespace App\Models;

use App\Enums\MaintenanceLogType;
use Illuminate\Database\Eloquent\Model;

class AssetMaintenanceLog extends Model
{
    protected $fillable = [
        'asset_id',
        'type',
        'date',
        'description',
        'cost',
        'performed_by',
        'next_service_date',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'cost' => 'decimal:2',
            'next_service_date' => 'date',
            'type' => MaintenanceLogType::class,
        ];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
