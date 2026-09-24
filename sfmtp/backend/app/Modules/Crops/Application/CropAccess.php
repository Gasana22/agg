<?php

namespace App\Modules\Crops\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Access\Domain\Enums\PermissionScope;
use App\Support\Http\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Record-level and field-level rules for crops (docs/04 §1):
 * - scopes: `all` sees every record, `own` what the member recorded, and
 *   `assigned` the cycles their tasks point to (tasks arrive in Phase 6, so
 *   for now an `assigned` member reaches no cycle);
 * - money (budgets, operation costs) is visible and writable only with
 *   `finance.values.view`.
 */
class CropAccess
{
    public function __construct(private readonly FarmPermissions $permissions) {}

    public function seesMoney(): bool
    {
        return $this->permissions->allows('finance.values.view');
    }

    public function can(string $permission): bool
    {
        return $this->permissions->allows($permission);
    }

    /** Filter records by the member's scope for a permission; `$ownerColumn` holds the recording user. */
    public function scoped(Builder $query, string $permission, string $ownerColumn = 'recorded_by'): Builder
    {
        return match ($this->permissions->scope($permission)) {
            PermissionScope::All => $query,
            PermissionScope::Own => $query->where($query->qualifyColumn($ownerColumn), Auth::id()),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Recording on a cycle needs an `all` scope until task assignments exist. */
    public function assertCanRecordOn(string $permission): void
    {
        $scope = $this->permissions->scope($permission);
        if ($scope !== PermissionScope::All) {
            throw ApiException::forbidden('not_assigned', 'You can only record work on crop cycles your tasks are assigned to.');
        }
    }

    /** @param  array<string,mixed>  $data */
    public function assertNoMoneyUnlessAllowed(array $data, array $fields): void
    {
        if ($this->seesMoney()) {
            return;
        }
        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                throw ApiException::forbidden('money_field_forbidden', 'You cannot record money values.');
            }
        }
    }
}
