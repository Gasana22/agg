<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /api/v1/admin/*. Only platform administrators reach the platform
 * surface; they, in turn, have no farm memberships (docs/02 §5).
 */
class RequirePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isPlatformAdmin()) {
            throw ApiException::forbidden('platform_admin_required', 'This area is for platform administrators.');
        }

        return $next($request);
    }
}
