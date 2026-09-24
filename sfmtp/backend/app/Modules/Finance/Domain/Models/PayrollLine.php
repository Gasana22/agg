<?php

namespace App\Modules\Finance\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Modules\Workforce\Domain\Models\Worker;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLine extends Model
{
    use BelongsToFarm, HasUuids;

    protected $fillable = ['farm_id', 'run_id', 'worker_id', 'days_worked', 'minutes_worked', 'tasks_verified', 'daily_rate', 'bonus', 'gross', 'deductions', 'net', 'note', 'allocation'];

    protected function casts(): array
    {
        return ['daily_rate' => 'decimal:2', 'bonus' => 'decimal:2', 'gross' => 'decimal:2', 'deductions' => 'decimal:2', 'net' => 'decimal:2',
            'days_worked' => 'integer', 'minutes_worked' => 'integer', 'tasks_verified' => 'integer', 'allocation' => 'array'];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'worker_id');
    }
}
