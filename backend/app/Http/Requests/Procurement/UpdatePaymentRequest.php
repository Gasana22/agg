<?php

namespace App\Http\Requests\Procurement;

use App\Enums\PaymentMethod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentRequest extends FormRequest
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
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'payment_date' => ['sometimes', 'date'],
            'method' => ['sometimes', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
