<?php

namespace App\Http\Requests\CropManagement;

use App\Enums\CropMonitoringType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCropMonitoringLogRequest extends FormRequest
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
            'type' => ['required', Rule::enum(CropMonitoringType::class)],
            'date' => ['required', 'date'],
            'description' => ['required', 'string'],
            'severity' => ['nullable', 'string', 'max:50'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
