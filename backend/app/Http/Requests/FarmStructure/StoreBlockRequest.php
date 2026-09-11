<?php

namespace App\Http\Requests\FarmStructure;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBlockRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('blocks')->where('farm_id', $this->route('farm')?->id),
            ],
            'gps_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'boundary' => ['nullable', 'array', 'min:3'],
            'boundary.*.lat' => ['required_with:boundary', 'numeric', 'between:-90,90'],
            'boundary.*.lng' => ['required_with:boundary', 'numeric', 'between:-180,180'],
        ];
    }
}
