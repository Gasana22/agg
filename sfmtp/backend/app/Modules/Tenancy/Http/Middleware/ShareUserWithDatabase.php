<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells the row-level-security policies who is signed in (app.user_id), so
 * a user can read their own memberships, and their farms' roles and
 * settings, before a farm is chosen ("My farms", workspaces, sign-in).
 */
class ShareUserWithDatabase
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->actAs($request->user('api')?->getAuthIdentifier());

        try {
            return $next($request);
        } finally {
            $this->context->actAs(null);
        }
    }
}
