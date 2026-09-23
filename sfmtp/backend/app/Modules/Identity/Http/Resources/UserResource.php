<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'user',
            'user_type' => $this->user_type->value,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'mfa_enabled' => $this->hasMfa(),
            'last_login_at' => $this->last_login_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
