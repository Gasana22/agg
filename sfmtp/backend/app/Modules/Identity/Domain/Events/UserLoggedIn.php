<?php

namespace App\Modules\Identity\Domain\Events;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class UserLoggedIn
{
    use Dispatchable;

    public function __construct(public User $user, public string $client) {}
}
