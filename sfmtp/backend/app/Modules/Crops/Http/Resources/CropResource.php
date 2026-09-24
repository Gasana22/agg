<?php

namespace App\Modules\Crops\Http\Resources;

use App\Modules\Crops\Domain\Models\Crop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Crop */
class CropResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'crop',
            'name' => $this->name,
            'variety' => $this->variety,
            'label' => $this->label(),
            'global_crop_id' => $this->global_crop_id,
            'global_variety_id' => $this->global_variety_id,
            'maturity_days' => $this->maturity_days,
            'yield_unit' => $this->yield_unit,
            'is_active' => $this->is_active,
        ];
    }
}
