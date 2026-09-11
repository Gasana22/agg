<?php

namespace App\Http\Requests\LivestockManagement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBreedingRecordRequest extends FormRequest
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
            'expected_due_date' => ['nullable', 'date'],
            'actual_birth_date' => ['nullable', 'date'],
            'offspring_count' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['bred', 'confirmed', 'delivered', 'failed'])],
            'notes' => ['nullable', 'string'],
        ];
    }
}
