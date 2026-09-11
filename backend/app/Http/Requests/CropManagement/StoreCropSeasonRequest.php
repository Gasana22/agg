<?php

namespace App\Http\Requests\CropManagement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCropSeasonRequest extends FormRequest
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
            'crop_id' => ['required', 'integer', 'exists:crops,id'],
            'plot_id' => ['nullable', 'integer', 'exists:plots,id'],
            'season_name' => ['required', 'string', 'max:255'],
            'planned_planting_date' => ['nullable', 'date'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'expected_yield' => ['nullable', 'numeric', 'min:0'],
            'expected_yield_unit' => ['nullable', 'string', 'max:50'],
        ];
    }
}
