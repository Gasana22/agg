<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Contracts\MfaRequirement;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Users whose role requires MFA (platform admins, farm owners, accountants …)
 * can only reach MFA enrolment, their profile and logout until MFA is on.
 */
class EnsureMfaCompliant
{
    private const ALLOWED_ROUTES = ['auth.logout', 'auth.mfa.setup', 'auth.mfa.confirm', 'me.show', 'me.workspaces'];

    public function __construct(private readonly MfaRequirement $requirement) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->hasMfa()
            && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)
            && $this->requirement->requiresMfa($user)) {
            throw ApiException::forbidden('mfa_setup_required', 'Your role requires multi-factor authentication. Set it up to continue.');
        }

        return $next($request);
    }
}
