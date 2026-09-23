<?php

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class LoginFailed
{
    use Dispatchable;

    public function __construct(public string $email, public ?User $user, public string $reason) {}
}
