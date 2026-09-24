<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentLine extends Model
{
    use BelongsToFarm, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['farm_id', 'shipment_id', 'position', 'trace_batch_id', 'quantity', 'unit', 'description', 'sales_order_line_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'position' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new AppendOnlyViolation);
        static::deleting(fn () => throw new AppendOnlyViolation);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TraceBatch::class, 'trace_batch_id');
    }
}
