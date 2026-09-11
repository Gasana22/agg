<?php

namespace App\Http\Requests\LivestockManagement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePoultryMortalityLogRequest extends FormRequest
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
        $log = $this->route('poultryMortalityLog');
        $flock = $log?->flock;

        return [
            'date' => ['sometimes', 'required', 'date'],
            'quantity' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                function (string $attribute, mixed $value, \Closure $fail) use ($flock, $log) {
                    if (! $flock) {
                        return;
                    }
                    // This log's own quantity is already subtracted from
                    // currentCount() — add it back before checking the new
                    // value against what's actually available.
                    $available = $flock->currentCount() + $log->quantity;
                    if ($value > $available) {
                        $fail("Only {$available} birds are available for this log.");
                    }
                },
            ],
            'cause' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
