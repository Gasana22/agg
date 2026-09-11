<?php

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryTransactionType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * quantity is always a positive magnitude — type (stock_in/stock_out)
     * determines the direction it moves the running balance.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(InventoryTransactionType::class)],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'date' => ['required', 'date'],
            'delivery_id' => ['nullable', 'integer', 'exists:deliveries,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
