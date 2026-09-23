<?php

namespace App\Modules\Access\Http\Resources;

use App\Modules\Access\Domain\Models\FarmRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FarmRole */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'farm_role',
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => $this->is_system,
            'is_locked' => $this->is_locked,
            'member_count' => $this->when($this->resource->hasAttribute('member_count'), fn () => $this->member_count),
            'grants' => $this->whenLoaded('permissions', fn () => $this->permissions
                ->sortBy('key')
                ->mapWithKeys(fn ($p) => [$p->key => $p->pivot->scope])),
        ];
    }
}
