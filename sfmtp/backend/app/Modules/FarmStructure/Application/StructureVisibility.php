<?php

namespace App\Modules\FarmStructure\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Access\Domain\Enums\PermissionScope;
use App\Modules\FarmStructure\Domain\Models\StructureNode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Applies the member's `structure.view` scope (docs/04 §1):
 * `all` sees everything, `own` sees what they created, and `assigned` sees
 * the plots their tasks point to, which arrive with tasks in Phase 6. Until
 * then an `assigned` member sees no structure.
 */
class StructureVisibility
{
    public function __construct(private readonly FarmPermissions $permissions) {}

    public function scope(): ?PermissionScope
    {
        return $this->permissions->scope('structure.view');
    }

    public function apply(Builder $query): Builder
    {
        return match ($this->scope()) {
            PermissionScope::All => $query,
            PermissionScope::Own => $query->where($query->qualifyColumn('created_by'), Auth::id()),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function canSee(StructureNode $node): bool
    {
        return match ($this->scope()) {
            PermissionScope::All => true,
            PermissionScope::Own => $node->created_by === Auth::id(),
            default => false,
        };
    }
}
