<?php

namespace App\Modules\Traceability\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only, hash-chained record of something that happened to a batch.
 * Written only by the Recorder; corrected by new events, never edited.
 *
 * @property string $id
 * @property string $farm_id
 * @property string $batch_id
 * @property int $farm_seq
 * @property string $hash
 * @property string $prev_hash
 */
class TraceEvent extends Model
{
    use BelongsToFarm, HasUuids;

    public $timestamps = false;

    /** Microsecond precision: the stored time must equal the hashed time. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'farm_seq' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new AppendOnlyViolation);
        static::deleting(fn () => throw new AppendOnlyViolation);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TraceBatch::class, 'batch_id');
    }
}
