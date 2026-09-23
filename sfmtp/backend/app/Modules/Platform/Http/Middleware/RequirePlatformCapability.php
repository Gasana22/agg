<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Application\PlatformPermissions;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `platform.can:<capability>` on /api/v1/admin routes (after platform.admin).
 */
class RequirePlatformCapability
{
    public function __construct(private readonly PlatformPermissions $permissions) {}

    public function handle(Request $request, Closure $next, string ...$capabilities): Response
    {
        foreach ($capabilities as $capability) {
            if (! $this->permissions->allows($request->user(), $capability)) {
                throw new ApiException(403, 'forbidden', 'Your platform role does not allow this.', ['required_capability' => $capability]);
            }
        }

        return $next($request);
    }
}
