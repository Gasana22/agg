<?php

namespace App\Http\Requests\FarmStructure;

use App\Enums\FarmRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFarmMemberRequest extends FormRequest
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
            'role_on_farm' => ['required', Rule::enum(FarmRole::class)],
        ];
    }
}
