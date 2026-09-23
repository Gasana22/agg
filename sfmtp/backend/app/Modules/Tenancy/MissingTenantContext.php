<?php

namespace App\Modules\Tenancy;

use LogicException;

/**
 * A farm-scoped model was queried with no tenant context. This is always a
 * programming error: wrap the code in TenantContext::run() (or, for
 * deliberate platform-level work, TenantContext::bypass()).
 */
class MissingTenantContext extends LogicException
{
    public function __construct()
    {
        parent::__construct('No tenant context: farm-scoped data cannot be read or written outside TenantContext::run().');
    }
}
