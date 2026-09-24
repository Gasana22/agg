<?php

namespace App\Modules\Crops\Domain\Models;

use App\Modules\Crops\Domain\Enums\PlanStatus;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the farm intends to grow in a season: crop, area, expected yield and
 * budget. Approved by the owner before cycles can start against it.
 *
 * @property string $id
 * @property string $code
 * @property PlanStatus $status
 * @property string $crop_id
 * @property string $season_id
 * @property string|null $created_by
 */
class CropPlan extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'name', 'season_id', 'crop_id', 'planned_area_ha', 'expected_yield', 'yield_unit', 'budget_amount', 'notes', 'created_by'];

    protected function casts(): array
    {
        return [
            'status' => PlanStatus::class,
            'planned_area_ha' => 'decimal:4',
            'expected_yield' => 'decimal:3',
            'budget_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(CropCycle::class, 'plan_id');
    }
}
