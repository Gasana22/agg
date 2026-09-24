<?php

namespace App\Modules\Access\Http\Middleware;

use App\Modules\Access\Application\FarmPermissions;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `farm.can:perm.one,perm.two` — the member must hold every
 * listed permission in the current farm (docs/02 §2, layer 3). An argument
 * `perm.a|perm.b` is satisfied by either permission.
 */
class RequireFarmPermission
{
    public function __construct(private readonly FarmPermissions $permissions) {}

    public function handle(Request $request, Closure $next, string ...$required): Response
    {
        foreach ($required as $permission) {
            $alternatives = explode('|', $permission);
            if (! array_filter($alternatives, fn (string $p) => $this->permissions->allows($p))) {
                throw new ApiException(403, 'forbidden', 'You do not have permission to do this in this farm.', [
                    'required_permission' => $permission,
                ]);
            }
        }

        return $next($request);
    }
}
