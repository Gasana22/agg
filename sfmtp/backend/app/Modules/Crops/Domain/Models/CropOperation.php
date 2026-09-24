<?php

namespace App\Modules\Crops\Domain\Models;

use App\Modules\Crops\Domain\Enums\OperationStatus;
use App\Modules\Crops\Domain\Enums\OperationType;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Field work on a cycle: weeding, spraying, fertilizing … with the inputs
 * used. It reaches the trace history once verified.
 *
 * @property string $id
 * @property string $cycle_id
 * @property OperationType $type
 * @property OperationStatus $status
 * @property Carbon $occurred_at
 * @property string|null $recorded_by
 */
class CropOperation extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = [
        'farm_id', 'cycle_id', 'observation_id', 'type', 'occurred_at', 'status', 'notes', 'labour_hours',
        'cost_amount', 'latitude', 'longitude', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => OperationType::class,
            'status' => OperationStatus::class,
            'occurred_at' => 'datetime',
            'verified_at' => 'datetime',
            'labour_hours' => 'decimal:2',
            'cost_amount' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'version' => 'integer',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class, 'cycle_id');
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(CropOperationInput::class, 'operation_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
