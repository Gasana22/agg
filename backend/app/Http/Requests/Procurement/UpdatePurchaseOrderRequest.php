<?php

namespace App\Http\Requests\Procurement;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Line items are managed separately (nested item routes) — this only
     * updates the order's own header fields.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['sometimes', 'integer', 'exists:suppliers,id'],
            'order_date' => ['sometimes', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::enum(PurchaseOrderStatus::class)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
