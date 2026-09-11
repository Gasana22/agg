<?php

namespace App\Models;

use App\Enums\AssetStatus;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    protected $fillable = [
        'farm_id',
        'name',
        'category',
        'serial_number',
        'purchase_date',
        'purchase_cost',
        'status',
        'assigned_to',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'purchase_cost' => 'decimal:2',
            'status' => AssetStatus::class,
        ];
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function maintenanceLogs()
    {
        return $this->hasMany(AssetMaintenanceLog::class);
    }
}
