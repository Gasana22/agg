<?php

namespace App\Modules\Tenancy\Domain\Concerns;

use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\MissingTenantContext;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * For every farm-owned model (docs/02-tenant-isolation.md §2, layer 4):
 * - reads are filtered to the current farm; with no context they throw;
 * - creates get farm_id from the context, and a mismatching farm_id throws.
 */
trait BelongsToFarm
{
    public static function bootBelongsToFarm(): void
    {
        static::addGlobalScope('farm', function (Builder $builder) {
            $context = app(TenantContext::class);

            if ($context->hasFarm()) {
                $builder->where($builder->qualifyColumn('farm_id'), $context->farmId());
            } elseif (! $context->isBypassed()) {
                throw new MissingTenantContext;
            }
        });

        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if (! $context->hasFarm()) {
                if ($context->isBypassed() && $model->farm_id) {
                    return;
                }
                throw new MissingTenantContext;
            }

            $model->farm_id ??= $context->farmId();

            if ($model->farm_id !== $context->farmId()) {
                throw new LogicException('Refusing to create a record for a different farm than the current tenant context.');
            }
        });
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }
}
