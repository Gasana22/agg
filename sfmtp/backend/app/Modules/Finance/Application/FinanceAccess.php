<?php

namespace App\Modules\Finance\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Tenancy\Application\FarmSettings;
use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;

/**
 * Who may do what with money (docs/04 §3): the accountant records, the owner
 * (or a role granted finance.approve) approves above the farm's thresholds,
 * and nobody approves their own request unless they are the owner.
 */
class FinanceAccess
{
    public function __construct(
        private readonly FarmPermissions $permissions,
        private readonly TenantContext $context,
        private readonly FarmSettings $settings,
    ) {}

    /** Any of `a|b`. */
    public function can(string $permission): bool
    {
        foreach (explode('|', $permission) as $p) {
            if ($this->permissions->allows($p)) {
                return true;
            }
        }

        return false;
    }

    public function assert(string $permission): void
    {
        if (! $this->can($permission)) {
            throw ApiException::forbidden('forbidden', 'You do not have permission to do this.');
        }
    }

    public function seesValues(): bool
    {
        return $this->can('finance.values.view');
    }

    public function isOwner(): bool
    {
        return (bool) $this->context->membership()?->is_owner;
    }

    /** The farm's approval threshold for a kind of document, or null when none is set. */
    public function threshold(string $key): ?float
    {
        $value = $this->settings->get($this->context->farm())['approval_thresholds'][$key] ?? null;

        return $value === null ? null : (float) $value;
    }

    /** Four eyes: the author may not decide unless they are the owner. */
    public function assertNotOwn(?string $authorId, string $what): void
    {
        if ($authorId !== null && $authorId === auth()->id() && ! $this->isOwner()) {
            throw ApiException::forbidden('four_eyes', "Someone else must {$what}.");
        }
    }
}
