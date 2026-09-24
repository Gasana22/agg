<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Domain\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'customer',
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
