<?php

namespace App\Http\Requests\CropManagement;

use App\Enums\CropMonitoringType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCropMonitoringLogRequest extends FormRequest
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
            'type' => ['sometimes', Rule::enum(CropMonitoringType::class)],
            'date' => ['sometimes', 'required', 'date'],
            'description' => ['sometimes', 'required', 'string'],
            'severity' => ['nullable', 'string', 'max:50'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
