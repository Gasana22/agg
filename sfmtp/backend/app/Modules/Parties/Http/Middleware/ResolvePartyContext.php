<?php

namespace App\Modules\Parties\Http\Middleware;

use App\Modules\Parties\Application\PartyContext;
use App\Modules\Parties\Domain\Models\Party;
use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal routes: `party` (any portal of the party) or `party:supplier` /
 * `party:customer`. The caller must be one of the party's people, and the
 * party must hold a link of that kind; anything else is a 404, so party
 * IDs cannot be probed. A route with {farm} also needs a link to that farm,
 * and runs in the farm's context, so bound records resolve through the
 * farm scope and RLS (without a membership: portal code never uses farm
 * permissions).
 */
class ResolvePartyContext
{
    public function __construct(
        private readonly PartyContext $parties,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next, ?string $kind = null): Response
    {
        $partyId = $request->route()?->originalParameter('party');
        $user = $request->user();
        if (! is_string($partyId) || ! Str::isUuid($partyId) || $user === null) {
            throw ApiException::notFound();
        }
        $party = Party::find($partyId);
        if ($party === null || ! $party->hasUser($user->getAuthIdentifier())) {
            throw ApiException::notFound();
        }
        if ($party->status !== 'active') {
            throw ApiException::forbidden('party_suspended', 'This portal account is suspended.');
        }

        $this->parties->enter($party);
        try {
            if ($kind !== null && $this->parties->links($kind)->isEmpty()) {
                throw ApiException::notFound();
            }
            $farmId = $request->route()?->originalParameter('farm');
            if ($farmId === null) {
                return $next($request);
            }
            if ($kind === null || ! is_string($farmId) || ! Str::isUuid($farmId)) {
                throw ApiException::notFound();
            }
            $link = $this->parties->linkTo($kind, $farmId);
            $this->parties->setLink($link);
            $this->tenant->enter($link->farm);
            try {
                return $next($request);
            } finally {
                $this->tenant->leave();
            }
        } finally {
            $this->parties->leave();
        }
    }
}
