<?php

namespace App\Modules\Finance\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Wages for a period, computed from attendance at each worker's daily rate.
 *
 * @property string $status draft | approved | paid | cancelled
 */
class PayrollRun extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'status', 'period_start', 'period_end', 'total_gross', 'total_deductions', 'total_net', 'paid_amount', 'notes', 'prepared_by'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'total_gross' => 'decimal:2', 'total_deductions' => 'decimal:2', 'total_net' => 'decimal:2',
            'paid_amount' => 'decimal:2', 'approved_at' => 'datetime', 'version' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class, 'run_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
