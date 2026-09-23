<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Domain\Enums\MembershipStatus;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves {farm} from the route and verifies the caller's active membership
 * (docs/02-tenant-isolation.md §2, layer 2).
 *
 * A missing membership is a 404, never a 403, so farm IDs cannot be probed.
 */
class ResolveFarmContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $farmId = $request->route()?->originalParameter('farm');
        $user = $request->user();

        if (! is_string($farmId) || ! Str::isUuid($farmId) || $user === null) {
            throw ApiException::notFound();
        }

        $membership = FarmUser::with('farm')
            ->where('farm_id', $farmId)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', MembershipStatus::Active->value)
            ->first();

        if ($membership === null || $membership->farm === null) {
            throw ApiException::notFound();
        }

        if (! $membership->farm->status->allowsMemberAccess()) {
            throw $membership->farm->status->value === 'closed'
                ? ApiException::notFound()
                : ApiException::forbidden('farm_suspended', 'This farm is suspended. Contact the farm owner.');
        }

        $this->context->enter($membership->farm, $membership);

        try {
            return $next($request);
        } finally {
            $this->context->leave();
        }
    }
}
