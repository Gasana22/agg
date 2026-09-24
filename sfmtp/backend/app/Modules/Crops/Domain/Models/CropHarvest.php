<?php

namespace App\Modules\Crops\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A harvest from a cycle. Append-only; its harvest batch carries it into the
 * traceability graph.
 *
 * @property string $id
 * @property string $cycle_id
 * @property string $quantity
 * @property string $unit
 * @property Carbon $harvested_on
 * @property string $trace_batch_id
 */
class CropHarvest extends Model
{
    use BelongsToFarm, HasUuids;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'farm_id', 'cycle_id', 'harvested_on', 'quantity', 'unit', 'quality_grade', 'moisture_pct', 'notes',
        'trace_batch_id', 'withholding_override_reason', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['harvested_on' => 'date', 'quantity' => 'decimal:3', 'moisture_pct' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new AppendOnlyViolation);
        static::deleting(fn () => throw new AppendOnlyViolation);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class, 'cycle_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TraceBatch::class, 'trace_batch_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
