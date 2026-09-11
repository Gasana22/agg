<?php

namespace App\Http\Requests\Traceability;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTraceBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * source_type is a friendly alias, not the model's FQCN — the
     * controller maps it to the real Eloquent class. source_id's
     * existence is checked in the controller, since which table it
     * belongs to depends on source_type.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source_type' => ['required', Rule::in(['crop_harvest', 'animal_production_record'])],
            'source_id' => ['required', 'integer'],
        ];
    }
}
