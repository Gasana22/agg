<?php

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFarmRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') || $this->isMethod('PUT') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:150'],
            'organization_name' => ['sometimes', 'string', 'max:150'],
            'district' => ['nullable', 'string', 'max:120'],
            'village' => ['nullable', 'string', 'max:120'],
            'country' => ['sometimes', 'string', 'size:2', 'alpha'],
            'size_ha' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'timezone' => ['sometimes', 'timezone:all'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['country', 'currency'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => strtoupper($this->input($key))]);
            }
        }
    }
}
