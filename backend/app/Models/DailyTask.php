<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Model;

class DailyTask extends Model
{
    protected $fillable = [
        'farm_id',
        'assigned_to',
        'assigned_by',
        'title',
        'description',
        'due_date',
        'status',
        'completed_at',
        'cost',
        'gps_lat',
        'gps_lng',
        'photo_path',
        'inputs_used',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'status' => TaskStatus::class,
            'completed_at' => 'datetime',
            'cost' => 'decimal:2',
            'gps_lat' => 'decimal:7',
            'gps_lng' => 'decimal:7',
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

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
