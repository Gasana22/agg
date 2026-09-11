<?php

namespace App\Http\Requests\LivestockManagement;

use App\Enums\AnimalStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAnimalRequest extends FormRequest
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
        $animal = $this->route('animal');

        return [
            'tag_number' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('animals')->where('farm_id', $animal?->farm_id)->ignore($animal),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'breed' => ['nullable', 'string', 'max:100'],
            'sex' => ['nullable', 'string', 'in:male,female'],
            'birth_date' => ['nullable', 'date'],
            'dam_id' => ['nullable', 'integer', 'exists:animals,id'],
            'sire_id' => ['nullable', 'integer', 'exists:animals,id'],
            'source' => ['nullable', 'string', 'in:born_on_farm,purchased'],
            'acquired_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::enum(AnimalStatus::class)],
            'death_date' => ['nullable', 'date'],
            'cause_of_death' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
