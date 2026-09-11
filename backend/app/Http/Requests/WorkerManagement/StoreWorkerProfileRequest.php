<?php

namespace App\Http\Requests\WorkerManagement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkerProfileRequest extends FormRequest
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
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                Rule::unique('worker_profiles')->where('farm_id', $this->route('farm')?->id),
            ],
            'employee_id' => ['nullable', 'string', 'max:255'],
            'hire_date' => ['nullable', 'date'],
            'daily_rate' => ['nullable', 'numeric', 'min:0'],
            'supervisor_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
