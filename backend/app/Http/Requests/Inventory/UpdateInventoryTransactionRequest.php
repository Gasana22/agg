<?php

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryTransactionType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryTransactionRequest extends FormRequest
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
            'type' => ['sometimes', Rule::enum(InventoryTransactionType::class)],
            'quantity' => ['sometimes', 'numeric', 'min:0.01'],
            'date' => ['sometimes', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
