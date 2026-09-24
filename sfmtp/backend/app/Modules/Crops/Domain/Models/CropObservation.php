<?php

namespace App\Modules\Crops\Domain\Models;

use App\Modules\Crops\Domain\Enums\ObservationKind;
use App\Modules\Crops\Domain\Enums\ObservationStatus;
use App\Modules\Crops\Domain\Enums\Severity;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A scouting finding: a pest, a disease, a weed flush, nutrient stress …
 *
 * @property string $id
 * @property string $cycle_id
 * @property ObservationKind $kind
 * @property Severity $severity
 * @property ObservationStatus $status
 * @property string $title
 */
class CropObservation extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = [
        'farm_id', 'cycle_id', 'kind', 'severity', 'title', 'description', 'affected_pct', 'observed_at',
        'latitude', 'longitude', 'status', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ObservationKind::class,
            'severity' => Severity::class,
            'status' => ObservationStatus::class,
            'observed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'affected_pct' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'version' => 'integer',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class, 'cycle_id');
    }

    public function treatments(): HasMany
    {
        return $this->hasMany(CropOperation::class, 'observation_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
