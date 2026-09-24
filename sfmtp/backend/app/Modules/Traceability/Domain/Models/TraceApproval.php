<?php

namespace App\Modules\Traceability\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The fields of a batch someone approved for the public, with the exact
 * payload they reviewed. A newer approval replaces it; none is edited.
 */
class TraceApproval extends Model
{
    use BelongsToFarm, HasUuids;

    public $timestamps = false;

    protected $fillable = ['farm_id', 'batch_id', 'public_fields', 'payload', 'note', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['public_fields' => 'array', 'payload' => 'array', 'approved_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new AppendOnlyViolation);
        static::deleting(fn () => throw new AppendOnlyViolation);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
