<?php

namespace App\Http\Requests\LivestockManagement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnimalRequest extends FormRequest
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
            'tag_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('animals')->where('farm_id', $farm?->id),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'species' => ['required', 'string', 'max:100'],
            'breed' => ['nullable', 'string', 'max:100'],
            'sex' => ['nullable', 'string', 'in:male,female'],
            'birth_date' => ['nullable', 'date'],
            'dam_id' => ['nullable', 'integer', 'exists:animals,id'],
            'sire_id' => ['nullable', 'integer', 'exists:animals,id'],
            'source' => ['nullable', 'string', 'in:born_on_farm,purchased'],
            'acquired_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
