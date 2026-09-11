<?php

namespace App\Http\Requests\CropManagement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCropSaleRequest extends FormRequest
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
            'buyer_name' => ['sometimes', 'required', 'string', 'max:255'],
            'quantity_sold' => ['sometimes', 'required', 'numeric', 'min:0'],
            'unit_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'sale_date' => ['sometimes', 'required', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
