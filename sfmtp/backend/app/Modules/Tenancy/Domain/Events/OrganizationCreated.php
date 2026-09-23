<?php

namespace App\Modules\Tenancy\Domain\Events;

use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched inside the transaction that creates an owner's organization. */
class OrganizationCreated
{
    use Dispatchable;

    public function __construct(public Organization $organization) {}
}
