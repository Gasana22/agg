<?php

namespace App\Modules\Billing\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $code
 * @property ?int $max_farms
 * @property ?int $max_users
 */
class Plan extends Model
{
    use HasUuids;

    protected $table = 'subscription_plans';

    protected $fillable = [
        'code', 'name', 'description', 'price', 'currency', 'billing_period', 'trial_days',
        'max_farms', 'max_users', 'max_storage_mb', 'features', 'is_active', 'is_public', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'trial_days' => 'integer',
            'max_farms' => 'integer',
            'max_users' => 'integer',
            'max_storage_mb' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** Price normalised to one month, for MRR. */
    public function monthlyPrice(): float
    {
        return $this->billing_period === 'yearly' ? (float) $this->price / 12 : (float) $this->price;
    }
}
