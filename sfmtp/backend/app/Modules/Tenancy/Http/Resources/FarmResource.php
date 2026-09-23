<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Domain\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Farm */
class FarmResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'farm',
            'organization_id' => $this->organization_id,
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status->value,
            'district' => $this->district,
            'village' => $this->village,
            'country' => $this->country,
            'size_ha' => $this->size_ha,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'approved_at' => $this->approved_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
