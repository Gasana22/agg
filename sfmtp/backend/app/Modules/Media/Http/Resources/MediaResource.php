<?php

namespace App\Modules\Media\Http\Resources;

use App\Modules\Media\Domain\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Media */
class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sha256' => $this->sha256,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'original_name' => $this->original_name,
            'created_at' => $this->created_at?->toIso8601ZuluString('millisecond'),
        ];
    }
}
