<?php

namespace App\Http\Requests\LivestockManagement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAnimalProductionRecordRequest extends FormRequest
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
            'date' => ['sometimes', 'required', 'date'],
            'product_type' => ['sometimes', 'required', 'string', 'max:100'],
            'quantity' => ['sometimes', 'required', 'numeric', 'min:0'],
            'unit' => ['sometimes', 'required', 'string', 'max:50'],
        ];
    }
}
