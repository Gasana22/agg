<?php

namespace App\Modules\Tenancy\Jobs;

/**
 * Implemented by queued jobs that work on one farm's data. The job must
 * serialize the farm id; RestoreTenantContext re-enters it before handle().
 */
interface TenantAware
{
    public function tenantFarmId(): string;
}
