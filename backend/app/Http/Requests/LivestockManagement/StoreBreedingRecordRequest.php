<?php

namespace App\Http\Requests\LivestockManagement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBreedingRecordRequest extends FormRequest
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
            'dam_id' => ['required', 'integer', 'exists:animals,id'],
            'sire_id' => ['nullable', 'integer', 'exists:animals,id'],
            'breeding_date' => ['required', 'date'],
            'expected_due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
