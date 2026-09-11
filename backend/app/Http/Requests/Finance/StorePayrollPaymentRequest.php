<?php

namespace App\Http\Requests\Finance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePayrollPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * days_worked/gross_amount/net_amount are computed server-side from
     * approved attendance in the period, not supplied by the caller.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'deductions' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
