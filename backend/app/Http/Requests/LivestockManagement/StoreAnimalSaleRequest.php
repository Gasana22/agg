<?php

namespace App\Http\Requests\LivestockManagement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAnimalSaleRequest extends FormRequest
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
            'buyer_name' => ['required', 'string', 'max:255'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'sale_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
