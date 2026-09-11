<?php

namespace App\Http\Requests\LivestockManagement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePoultryFlockRequest extends FormRequest
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
        $farm = $this->route('farm');

        return [
            'flock_code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('poultry_flocks')->where('farm_id', $farm?->id),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'bird_type' => ['required', 'string', 'max:100'],
            'breed' => ['nullable', 'string', 'max:100'],
            'initial_count' => ['required', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'in:born_on_farm,purchased'],
            'acquired_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
