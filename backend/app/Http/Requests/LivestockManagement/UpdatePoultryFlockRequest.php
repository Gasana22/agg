<?php

namespace App\Http\Requests\LivestockManagement;

use App\Enums\PoultryFlockStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePoultryFlockRequest extends FormRequest
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
        $flock = $this->route('poultryFlock');

        return [
            'flock_code' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('poultry_flocks')->where('farm_id', $flock?->farm_id)->ignore($flock),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'bird_type' => ['sometimes', 'required', 'string', 'max:100'],
            'breed' => ['nullable', 'string', 'max:100'],
            'initial_count' => ['sometimes', 'required', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'in:born_on_farm,purchased'],
            'acquired_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::enum(PoultryFlockStatus::class)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
