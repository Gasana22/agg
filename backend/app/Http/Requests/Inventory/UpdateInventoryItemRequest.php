<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryItemRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'unit' => ['sometimes', 'string', 'max:50'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
