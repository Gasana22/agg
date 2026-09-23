<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'client' => ['sometimes', 'string', 'in:web,mobile'],
            'device' => ['sometimes', 'array'],
            'device.name' => ['nullable', 'string', 'max:120'],
            'device.platform' => ['nullable', 'string', 'in:android,ios,web'],
            'device.push_token' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
