<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Domain\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'plan',
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'price' => ['amount' => $this->price, 'currency' => $this->currency],
            'billing_period' => $this->billing_period,
            'trial_days' => $this->trial_days,
            'limits' => [
                'farms' => $this->max_farms,
                'users' => $this->max_users,
                'storage_mb' => $this->max_storage_mb,
            ],
            'features' => $this->features ?? [],
            'is_active' => $this->is_active,
            'is_public' => $this->is_public,
            'sort_order' => $this->sort_order,
        ];
    }
}
