<?php

namespace App\Http\Requests\CropManagement;

use App\Enums\CropSeasonStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCropSeasonRequest extends FormRequest
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
            'plot_id' => ['nullable', 'integer', 'exists:plots,id'],
            'season_name' => ['sometimes', 'required', 'string', 'max:255'],
            'planned_planting_date' => ['nullable', 'date'],
            'actual_planting_date' => ['nullable', 'date'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'expected_yield' => ['nullable', 'numeric', 'min:0'],
            'expected_yield_unit' => ['nullable', 'string', 'max:50'],
            'status' => ['sometimes', Rule::enum(CropSeasonStatus::class)],
        ];
    }
}
