<?php

namespace App\Modules\Billing\Domain\Models;

use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property SubscriptionStatus $status
 * @property Carbon $current_period_start
 * @property Carbon $current_period_end
 * @property ?Carbon $grace_until
 */
class Subscription extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'plan_id', 'status', 'current_period_start', 'current_period_end',
        'grace_until', 'cancel_at_period_end', 'cancelled_at', 'suspended_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'current_period_start' => 'date',
            'current_period_end' => 'date',
            'grace_until' => 'date',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'datetime',
            'suspended_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (self $s) => $s->version = $s->getOriginal('version') + 1);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(SubscriptionHistory::class);
    }
}
