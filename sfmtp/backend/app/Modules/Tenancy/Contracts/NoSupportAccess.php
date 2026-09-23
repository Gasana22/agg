<?php

namespace App\Modules\Tenancy\Contracts;

class NoSupportAccess implements SupportAccessResolver
{
    public function activeGrantId(string $userId, string $farmId): ?string
    {
        return null;
    }
}
