<?php

namespace App\Http\Requests\FarmStructure;

use App\Enums\FarmRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddFarmMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The user must already have an account; this attaches them to the
     * farm, it doesn't create one.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'exists:users,email'],
            'role_on_farm' => ['required', Rule::enum(FarmRole::class)],
        ];
    }
}
