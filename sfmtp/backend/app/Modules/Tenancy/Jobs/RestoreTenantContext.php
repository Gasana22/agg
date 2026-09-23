<?php

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\TenantContext;
use Closure;

/**
 * Job middleware: `public function middleware() { return [new RestoreTenantContext]; }`
 */
class RestoreTenantContext
{
    public function handle(TenantAware $job, Closure $next): mixed
    {
        $farm = Farm::findOrFail($job->tenantFarmId());

        return app(TenantContext::class)->run($farm, fn () => $next($job));
    }
}
