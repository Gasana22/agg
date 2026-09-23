<?php

namespace App\Modules\Billing\Domain\Models;

use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SubscriptionHistory extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** Microsecond precision so entries written in the same second keep their order. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'subscription_status_history';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new AppendOnlyViolation);
        static::deleting(fn () => throw new AppendOnlyViolation);
    }
}
