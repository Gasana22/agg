<?php

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Domain\Models\User;

/**
 * Decides whether a user must have MFA enabled. Identity only knows that
 * platform admins always must; the Tenancy module binds a richer
 * implementation that adds farm-role rules (owner, accountant, farm policy).
 */
interface MfaRequirement
{
    public function requiresMfa(User $user): bool;
}
