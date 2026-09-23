<?php

namespace App\Modules\Tenancy\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Every request made under a support grant (audited by the Support module). */
class SupportAccessUsed
{
    use Dispatchable;

    public function __construct(
        public string $userId,
        public string $farmId,
        public string $grantId,
        public string $method,
        public string $path,
    ) {}
}
