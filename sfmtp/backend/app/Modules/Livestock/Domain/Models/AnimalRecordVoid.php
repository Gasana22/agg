<?php

namespace App\Modules\Livestock\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Marks an animal record as entered in error. */
class AnimalRecordVoid extends Model
{
    use BelongsToFarm, HasUuids;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['farm_id', 'record_type', 'record_id', 'reason', 'voided_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new AppendOnlyViolation);
        static::deleting(fn () => throw new AppendOnlyViolation);
    }
}
