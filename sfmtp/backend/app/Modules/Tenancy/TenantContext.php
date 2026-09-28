<?php

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Support\Database\Schema as Ddl;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The current tenant for this request or job (docs/02-tenant-isolation.md §2–3).
 *
 * Registered as a scoped singleton, so it is fresh for every request and
 * queued job. Setting it also sets PostgreSQL's app.farm_id, which the
 * row-level-security policies read, together with app.user_id (the signed-in
 * user, who may read their own memberships across farms) and app.platform
 * (platform administration, which may read the platform-facing farm tables
 * but no operational data).
 */
class TenantContext
{
    private ?Farm $farm = null;

    private ?FarmUser $membership = null;

    private bool $bypass = false;

    private ?string $userId = null;

    private bool $platform = false;

    /** Set during an owner-granted, read-only support session (ADR-0005). */
    private ?string $supportGrantId = null;

    /** Per-context memo (e.g. the member's effective permissions). */
    private array $memo = [];

    public function enter(Farm $farm, ?FarmUser $membership = null): void
    {
        $this->farm = $farm;
        $this->membership = $membership;
        $this->supportGrantId = null;
        $this->memo = [];
        $this->syncDatabase();
    }

    /** Enter a farm as platform support under a read-only grant. */
    public function enterSupport(Farm $farm, string $grantId): void
    {
        $this->enter($farm);
        $this->supportGrantId = $grantId;
    }

    public function leave(): void
    {
        $this->farm = null;
        $this->membership = null;
        $this->supportGrantId = null;
        $this->memo = [];
        $this->syncDatabase();
    }

    public function isSupportSession(): bool
    {
        return $this->supportGrantId !== null;
    }

    public function supportGrantId(): ?string
    {
        return $this->supportGrantId;
    }

    /**
     * Run a callback inside a farm's context (jobs, scheduled tasks, seeders),
     * restoring the previous context afterwards.
     */
    public function run(Farm $farm, Closure $callback, ?FarmUser $membership = null): mixed
    {
        [$prevFarm, $prevMembership, $prevMemo, $prevGrant] = [$this->farm, $this->membership, $this->memo, $this->supportGrantId];
        $this->enter($farm, $membership);

        try {
            return $callback();
        } finally {
            $this->farm = $prevFarm;
            $this->membership = $prevMembership;
            $this->memo = $prevMemo;
            $this->supportGrantId = $prevGrant;
            $this->syncDatabase();
        }
    }

    /**
     * Deliberately step outside tenant isolation (platform aggregates,
     * seeders, integrity jobs). Every use must be justified in review.
     */
    public function bypass(Closure $callback): mixed
    {
        $previous = $this->bypass;
        $this->bypass = true;
        $this->syncDatabase();

        try {
            return $callback();
        } finally {
            $this->bypass = $previous;
            $this->syncDatabase();
        }
    }

    /** The authenticated user, for the "own rows" policies (memberships, their farms' roles and settings). */
    public function actAs(?string $userId): void
    {
        if ($this->userId !== $userId) {
            $this->userId = $userId;
            $this->syncDatabase();
        }
    }

    /**
     * Run platform administration (the /admin routes): tickets, grants, farm
     * settings and status, audit and payments of every farm become readable;
     * operational farm tables stay closed.
     */
    public function platform(Closure $callback): mixed
    {
        $previous = $this->platform;
        $this->platform = true;
        $this->syncDatabase();

        try {
            return $callback();
        } finally {
            $this->platform = $previous;
            $this->syncDatabase();
        }
    }

    public function hasFarm(): bool
    {
        return $this->farm !== null;
    }

    public function isBypassed(): bool
    {
        return $this->bypass;
    }

    public function farm(): Farm
    {
        return $this->farm ?? throw new MissingTenantContext;
    }

    public function farmId(): string
    {
        return $this->farm()->id;
    }

    public function membership(): ?FarmUser
    {
        return $this->membership;
    }

    public function remember(string $key, Closure $resolver): mixed
    {
        return $this->memo[$key] ??= $resolver();
    }

    private function syncDatabase(): void
    {
        if (! Ddl::isPgsql()) {
            return;
        }

        try {
            DB::select("SELECT set_config('app.farm_id', ?, false), set_config('app.rls_bypass', ?, false), set_config('app.user_id', ?, false), set_config('app.platform', ?, false)", [
                $this->farm?->id ?? '',
                $this->bypass ? 'on' : 'off',
                $this->userId ?? '',
                $this->platform ? 'on' : 'off',
            ]);
        } catch (QueryException $e) {
            // 25P02: the surrounding transaction already failed. PostgreSQL
            // reverts settings made inside it on rollback, so there is nothing
            // to restore, and raising here would hide the original error.
            if ($e->getCode() !== '25P02') {
                throw $e;
            }
        }
    }
}
