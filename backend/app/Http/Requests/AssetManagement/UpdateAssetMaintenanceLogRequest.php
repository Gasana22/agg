<?php

namespace App\Http\Requests\AssetManagement;

use App\Enums\MaintenanceLogType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetMaintenanceLogRequest extends FormRequest
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
            'type' => ['sometimes', Rule::enum(MaintenanceLogType::class)],
            'date' => ['sometimes', 'date'],
            'description' => ['sometimes', 'string'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'performed_by' => ['nullable', 'string', 'max:255'],
            'next_service_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
