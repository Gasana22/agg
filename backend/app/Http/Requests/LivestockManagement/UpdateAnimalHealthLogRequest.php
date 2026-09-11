<?php

namespace App\Http\Requests\LivestockManagement;

use App\Enums\AnimalHealthLogType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAnimalHealthLogRequest extends FormRequest
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
            'type' => ['sometimes', Rule::enum(AnimalHealthLogType::class)],
            'date' => ['sometimes', 'required', 'date'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'next_due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
