<?php

namespace App\Http\Requests\Procurement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'item_name' => ['sometimes', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'quantity' => ['sometimes', 'numeric', 'min:0.01'],
            'unit' => ['sometimes', 'string', 'max:50'],
            'unit_price' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
