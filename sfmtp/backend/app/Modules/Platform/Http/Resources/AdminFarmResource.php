<?php

namespace App\Modules\Platform\Http\Resources;

use App\Modules\Tenancy\Domain\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Farm *metadata* for platform staff. Never operational records (docs/02 §5).
 *
 * @mixin Farm
 */
class AdminFarmResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();

        return [
            'id' => $this->id,
            'type' => 'admin_farm',
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status->value,
            'suspension_reason' => $this->suspension_reason,
            'district' => $this->district,
            'country' => $this->country,
            'size_ha' => $this->size_ha,
            'organization' => ['id' => $this->organization_id, 'name' => $this->organization?->name],
            'owner' => ($owner = $attributes['owner_user'] ?? null) ? ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email] : null,
            'member_count' => (int) ($attributes['member_count'] ?? 0),
            'subscription' => $attributes['subscription_summary'] ?? null,
            'history' => $this->when(array_key_exists('history', $attributes), fn () => $attributes['history']),
            'approved_at' => $this->approved_at?->toIso8601ZuluString(),
            'suspended_at' => $this->suspended_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
