<?php

namespace App\Http\Requests\CropManagement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCropRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('crops')
                    ->where('farm_id', $farm?->id)
                    ->where('variety', $this->input('variety')),
            ],
            'variety' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
