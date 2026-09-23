<?php

namespace App\Modules\Tenancy\Contracts;

/**
 * Owner-granted, read-only support access (ADR-0005). Returns the id of an
 * active grant letting this platform support user read this farm, or null.
 */
interface SupportAccessResolver
{
    public function activeGrantId(string $userId, string $farmId): ?string;
}
