<?php

namespace App\Modules\Tenancy\Domain\Events;

use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched inside the farm-creation transaction. Listeners (e.g. Access
 * installing role templates) run synchronously so the farm is complete.
 */
class FarmCreated
{
    use Dispatchable;

    public function __construct(public Farm $farm, public FarmUser $owner) {}
}
