<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Access\Domain\Enums\PermissionScope;
use App\Modules\Inventory\Domain\Models\InventoryRequest;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Inventory visibility (docs/04 §2): costs and stock values need
 * inventory.values.view; members see their own requests, the store and
 * approvers see all.
 */
class InventoryAccess
{
    public function __construct(private readonly FarmPermissions $permissions, private readonly TenantContext $context) {}

    public function can(string $permission): bool
    {
        return $this->permissions->allows($permission);
    }

    public function seesValues(): bool
    {
        return $this->permissions->allows('inventory.values.view');
    }

    public function isOwner(): bool
    {
        return (bool) $this->context->membership()?->is_owner;
    }

    public function requests(): Builder
    {
        $query = InventoryRequest::query();
        if ($this->can('inventory.stock.move') || $this->can('inventory.stock.approve') || $this->permissions->scope('inventory.requests.create') === PermissionScope::All) {
            return $query;
        }

        return $query->where('requested_by', Auth::id());
    }
}
