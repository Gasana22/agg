<?php

namespace App\Http\Requests\LivestockManagement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePoultrySaleRequest extends FormRequest
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
        $sale = $this->route('poultrySale');
        $flock = $sale?->flock;

        return [
            'quantity' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                function (string $attribute, mixed $value, \Closure $fail) use ($flock, $sale) {
                    if (! $flock) {
                        return;
                    }
                    // This sale's own quantity is already subtracted from
                    // currentCount() — add it back before checking the new
                    // value against what's actually available.
                    $available = $flock->currentCount() + $sale->quantity;
                    if ($value > $available) {
                        $fail("Only {$available} birds are available for this sale.");
                    }
                },
            ],
            'buyer_name' => ['sometimes', 'required', 'string', 'max:255'],
            'sale_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'sale_date' => ['sometimes', 'required', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
