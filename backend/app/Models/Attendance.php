<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $fillable = [
        'worker_profile_id',
        'date',
        'check_in_at',
        'check_in_lat',
        'check_in_lng',
        'check_in_photo_path',
        'check_out_at',
        'check_out_lat',
        'check_out_lng',
        'check_out_photo_path',
        'status',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'check_in_at' => 'datetime',
            'check_in_lat' => 'decimal:7',
            'check_in_lng' => 'decimal:7',
            'check_out_at' => 'datetime',
            'check_out_lat' => 'decimal:7',
            'check_out_lng' => 'decimal:7',
            'status' => AttendanceStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function workerProfile()
    {
        return $this->belongsTo(WorkerProfile::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
