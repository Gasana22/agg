<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Contracts\SubscriptionGate;
use App\Modules\Tenancy\Contracts\SupportAccessResolver;
use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\Domain\Enums\MembershipStatus;
use App\Modules\Tenancy\Domain\Events\SupportAccessUsed;
use App\Modules\Tenancy\Domain\Models\Farm;
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
 * The one exception is platform support holding an owner-granted,
 * read-only grant for this farm (ADR-0005).
 */
class ResolveFarmContext
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SubscriptionGate $subscriptions,
        private readonly SupportAccessResolver $support,
    ) {}

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
            return $this->supportSession($request, $next, $user, $farmId);
        }

        $farm = $membership->farm;
        $this->assertAccessible($farm);
        $this->subscriptions->assertFarmUsable($farm);

        $this->context->enter($farm, $membership);

        try {
            return $next($request);
        } finally {
            $this->context->leave();
        }
    }

    private function supportSession(Request $request, Closure $next, User $user, string $farmId): Response
    {
        $grantId = $user->isPlatformAdmin() ? $this->support->activeGrantId($user->id, $farmId) : null;
        $farm = $grantId ? Farm::find($farmId) : null;

        if ($farm === null || $farm->status === FarmStatus::Closed) {
            throw ApiException::notFound();
        }

        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw ApiException::forbidden('support_read_only', 'Support access is read-only.');
        }

        $this->context->enterSupport($farm, $grantId);
        SupportAccessUsed::dispatch($user->id, $farm->id, $grantId, $request->method(), $request->path());

        try {
            return $next($request);
        } finally {
            $this->context->leave();
        }
    }

    private function assertAccessible(Farm $farm): void
    {
        if (! $farm->status->allowsMemberAccess()) {
            throw $farm->status === FarmStatus::Closed
                ? ApiException::notFound()
                : ApiException::forbidden('farm_suspended', 'This farm is suspended. Contact the farm owner.');
        }
    }
}
