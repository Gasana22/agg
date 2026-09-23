<?php

namespace App\Modules\Traceability\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Enums\BatchStatus;
use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A traceable thing: lot, crop lot, harvest, animal, product, shipment.
 * Created and changed only through the Traceability Recorder.
 *
 * @property string $id
 * @property string $farm_id
 * @property string $batch_code
 * @property BatchKind $kind
 * @property BatchStatus $status
 */
class TraceBatch extends Model
{
    use BelongsToFarm, HasUuids;

    protected $fillable = [
        'farm_id', 'batch_code', 'kind', 'name', 'quantity', 'unit', 'status',
        'product_id', 'origin_plot_id', 'source_type', 'source_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => BatchKind::class,
            'status' => BatchStatus::class,
            'quantity' => 'decimal:3',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new AppendOnlyViolation);
        static::updating(fn (self $batch) => $batch->version = $batch->getOriginal('version') + 1);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TraceEvent::class, 'batch_id');
    }
}
