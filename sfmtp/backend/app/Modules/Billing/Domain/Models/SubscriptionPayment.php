<?php

namespace App\Modules\Billing\Domain\Models;

use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    use HasUuids;

    protected $fillable = [
        'subscription_id', 'amount', 'currency', 'provider', 'provider_ref', 'status',
        'period_start', 'period_end', 'paid_at', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new AppendOnlyViolation);
    }
}
