<?php

namespace App\Http\Requests\AssetManagement;

use App\Enums\MaintenanceLogType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetMaintenanceLogRequest extends FormRequest
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
            'type' => ['required', Rule::enum(MaintenanceLogType::class)],
            'date' => ['required', 'date'],
            'description' => ['required', 'string'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'performed_by' => ['nullable', 'string', 'max:255'],
            'next_service_date' => ['nullable', 'date', 'after_or_equal:date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
