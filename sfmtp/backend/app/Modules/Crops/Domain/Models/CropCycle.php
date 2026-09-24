<?php

namespace App\Modules\Crops\Domain\Models;

use App\Modules\Crops\Domain\Enums\CloseReason;
use App\Modules\Crops\Domain\Enums\CycleStage;
use App\Modules\Crops\Domain\Enums\PlantingMethod;
use App\Modules\FarmStructure\Domain\Models\Plot;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One crop grown on one plot, from sowing to the end of harvest. Its crop lot
 * is the trace batch every operation, observation and harvest hangs off.
 *
 * @property string $id
 * @property string $farm_id
 * @property string $code
 * @property CycleStage $stage
 * @property PlantingMethod $planting_method
 * @property string $plot_id
 * @property string $crop_id
 * @property string|null $plan_id
 * @property string $area_ha
 * @property Carbon|null $planted_on
 * @property Carbon|null $safe_harvest_on
 * @property string|null $crop_lot_batch_id
 * @property string|null $nursery_batch_id
 * @property string $yield_unit
 */
class CropCycle extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = [
        'farm_id', 'code', 'plan_id', 'season_id', 'plot_id', 'crop_id', 'planting_method', 'stage', 'area_ha',
        'sown_on', 'planted_on', 'expected_harvest_on', 'expected_yield', 'yield_unit', 'seeds_sown',
        'seedlings_germinated', 'seedlings_transplanted', 'seed_batch_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'stage' => CycleStage::class,
            'planting_method' => PlantingMethod::class,
            'close_reason' => CloseReason::class,
            'area_ha' => 'decimal:4',
            'expected_yield' => 'decimal:3',
            'sown_on' => 'date',
            'planted_on' => 'date',
            'expected_harvest_on' => 'date',
            'safe_harvest_on' => 'date',
            'closed_on' => 'date',
            'version' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return $this->stage !== CycleStage::Closed;
    }

    /** The batch field work is recorded against: the crop lot, or the nursery before transplanting. */
    public function workingBatch(): ?TraceBatch
    {
        return $this->cropLot ?? $this->nurseryBatch;
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class)->withTrashed();
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CropPlan::class, 'plan_id');
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function cropLot(): BelongsTo
    {
        return $this->belongsTo(TraceBatch::class, 'crop_lot_batch_id');
    }

    public function nurseryBatch(): BelongsTo
    {
        return $this->belongsTo(TraceBatch::class, 'nursery_batch_id');
    }

    public function seedBatch(): BelongsTo
    {
        return $this->belongsTo(TraceBatch::class, 'seed_batch_id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(CropOperation::class, 'cycle_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(CropObservation::class, 'cycle_id');
    }

    public function harvests(): HasMany
    {
        return $this->hasMany(CropHarvest::class, 'cycle_id');
    }
}
