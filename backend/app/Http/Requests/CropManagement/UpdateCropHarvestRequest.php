<?php

namespace App\Http\Requests\CropManagement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCropHarvestRequest extends FormRequest
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
            'harvest_date' => ['sometimes', 'required', 'date'],
            'quantity' => ['sometimes', 'required', 'numeric', 'min:0'],
            'unit' => ['sometimes', 'required', 'string', 'max:50'],
            'quality_grade' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
