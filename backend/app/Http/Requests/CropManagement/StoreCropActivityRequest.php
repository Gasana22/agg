<?php

namespace App\Http\Requests\CropManagement;

use App\Enums\CropActivityType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCropActivityRequest extends FormRequest
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
            'type' => ['required', Rule::enum(CropActivityType::class)],
            'date' => ['required', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'gps_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
