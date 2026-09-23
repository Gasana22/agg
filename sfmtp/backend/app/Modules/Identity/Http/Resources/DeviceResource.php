<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Domain\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserDevice */
class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'device',
            'client' => $this->client,
            'name' => $this->name,
            'platform' => $this->platform,
            'last_ip' => $this->last_ip,
            'last_seen_at' => $this->last_seen_at?->toIso8601ZuluString(),
            'current' => $this->id === $request->attributes->get('device_id'),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
