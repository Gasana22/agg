<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Contracts\Assignments;
use App\Modules\Access\Domain\Enums\PermissionScope;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Record-level and field-level rules for operational records (docs/04 §1):
 * - scopes: `all` sees every record, `own` what the member recorded, and
 *   `assigned` the records their tasks point to (see Assignments);
 * - money (budgets, costs, prices) is visible and writable only with
 *   `finance.values.view`.
 */
class ScopedAccess
{
    public function __construct(
        private readonly FarmPermissions $permissions,
        private readonly Assignments $assignments,
    ) {}

    public function seesMoney(): bool
    {
        return $this->permissions->allows('finance.values.view');
    }

    public function can(string $permission): bool
    {
        return $this->permissions->allows($permission);
    }

    /**
     * Filter records by the member's scope for a permission. `$ownerColumn`
     * holds the recording user; `$assigned` narrows the query to what the
     * member's tasks point to (without it, an `assigned` member sees none).
     *
     * @param  (Closure(Builder, Assignments): mixed)|null  $assigned
     */
    public function scoped(Builder $query, string $permission, string $ownerColumn = 'recorded_by', ?Closure $assigned = null): Builder
    {
        return match ($this->permissions->scope($permission)) {
            PermissionScope::All => $query,
            PermissionScope::Own => $query->where($query->qualifyColumn($ownerColumn), Auth::id()),
            PermissionScope::Assigned => $assigned ? $query->where(fn (Builder $q) => $assigned($q, $this->assignments)) : $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Recording on a subject needs an `all` scope, or an `assigned` scope
     * with an open task on one of the given subjects, e.g.
     * `['crop_cycle' => $cycle->id, 'plot' => $cycle->plot_id]`.
     *
     * @param  array<string, string|null>  $subjects
     */
    public function assertCanRecordOn(string $permission, array $subjects = []): void
    {
        $scope = $this->permissions->scope($permission);
        if ($scope === PermissionScope::All) {
            return;
        }
        if ($scope === PermissionScope::Assigned && $this->isAssignedTo($subjects)) {
            return;
        }
        throw ApiException::forbidden('not_assigned', 'You can only record work your tasks are assigned to.');
    }

    /** @param  array<string, string|null>  $subjects */
    public function isAssignedTo(array $subjects, bool $openOnly = true): bool
    {
        foreach ($subjects as $type => $id) {
            if ($id !== null && in_array($id, $this->assignments->subjectIds($type, $openOnly), true)) {
                return true;
            }
        }

        return false;
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
