<?php

namespace App\Http\Requests\LivestockManagement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePoultryMortalityLogRequest extends FormRequest
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
        $flock = $this->route('poultryFlock');

        return [
            'date' => ['required', 'date'],
            'quantity' => [
                'required',
                'integer',
                'min:1',
                function (string $attribute, mixed $value, \Closure $fail) use ($flock) {
                    if ($flock && $value > $flock->currentCount()) {
                        $fail("Only {$flock->currentCount()} birds remain in this flock.");
                    }
                },
            ],
            'cause' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
