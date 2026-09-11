<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkerProfile extends Model
{
    protected $fillable = [
        'farm_id',
        'user_id',
        'employee_id',
        'hire_date',
        'daily_rate',
        'supervisor_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'daily_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function payrollPayments()
    {
        return $this->hasMany(PayrollPayment::class);
    }
}
