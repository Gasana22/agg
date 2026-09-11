<?php

namespace App\Http\Requests\Traceability;

use App\Enums\TraceEventType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTraceEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * "produced" is excluded — that event is auto-created when the batch
     * is registered, never added by hand afterward.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => [
                'required',
                Rule::enum(TraceEventType::class)->except(TraceEventType::Produced),
            ],
            'date' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
