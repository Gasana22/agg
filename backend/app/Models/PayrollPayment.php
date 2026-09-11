<?php

namespace App\Models;

use App\Enums\PayrollPaymentStatus;
use Illuminate\Database\Eloquent\Model;

class PayrollPayment extends Model
{
    protected $fillable = [
        'farm_id',
        'worker_profile_id',
        'period_start',
        'period_end',
        'days_worked',
        'gross_amount',
        'deductions',
        'net_amount',
        'status',
        'paid_date',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'gross_amount' => 'decimal:2',
            'deductions' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'status' => PayrollPaymentStatus::class,
            'paid_date' => 'date',
        ];
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function workerProfile()
    {
        return $this->belongsTo(WorkerProfile::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
