<?php

namespace App\Modules\Procurement\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Tenancy\TenantContext;

/**
 * Who sees prices (docs/04 §2): buyers (procurement.orders.manage) and
 * members with finance.values.view. The store receives deliveries and sees
 * quantities only.
 */
class ProcurementAccess
{
    public function __construct(private readonly FarmPermissions $permissions, private readonly TenantContext $context) {}

    public function can(string $permission): bool
    {
        return $this->permissions->allows($permission);
    }

    public function seesPrices(): bool
    {
        return $this->can('procurement.orders.manage') || $this->can('finance.values.view');
    }

    public function isOwner(): bool
    {
        return (bool) $this->context->membership()?->is_owner;
    }
}
