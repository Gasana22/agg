<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Contracts\MfaRequirement;
use App\Modules\Identity\Domain\Models\User;

class PlatformAdminMfaRequirement implements MfaRequirement
{
    public function requiresMfa(User $user): bool
    {
        return $user->isPlatformAdmin();
    }
}
