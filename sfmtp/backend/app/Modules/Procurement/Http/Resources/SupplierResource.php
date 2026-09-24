<?php

namespace App\Modules\Procurement\Http\Resources;

use App\Modules\Procurement\Domain\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Supplier */
class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'supplier',
            'code' => $this->code,
            'name' => $this->name,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'tax_id' => $this->tax_id,
            'payment_terms_days' => $this->payment_terms_days,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'version' => $this->version,
        ];
    }
}
