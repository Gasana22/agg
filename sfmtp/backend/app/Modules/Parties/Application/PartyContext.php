<?php

namespace App\Modules\Parties\Application;

use App\Modules\Parties\Domain\Models\Party;
use App\Modules\Parties\Domain\Models\PartyLink;
use App\Modules\Tenancy\Contracts\SubscriptionGate;
use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The party a portal request acts for (docs/02 §3). Portal reads never step
 * outside tenant isolation: a list across farms runs once per linked farm,
 * inside that farm's context, and a detail route names its farm, which must
 * be linked. A farm that is suspended, closed or unpaid drops out of the
 * portal like it drops out for its members.
 */
class PartyContext
{
    private ?Party $party = null;

    private ?PartyLink $link = null;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SubscriptionGate $subscriptions,
    ) {}

    public function enter(Party $party): void
    {
        $this->party = $party;
        $this->link = null;
    }

    public function leave(): void
    {
        $this->party = null;
        $this->link = null;
    }

    public function party(): Party
    {
        return $this->party ?? throw ApiException::notFound();
    }

    /** The link of the farm this request is about (routes with {farm}). */
    public function link(): PartyLink
    {
        return $this->link ?? throw ApiException::notFound();
    }

    public function setLink(PartyLink $link): void
    {
        $this->link = $link;
    }

    /** @return Collection<int, PartyLink> active links of a kind to farms that are open */
    public function links(string $kind): Collection
    {
        return PartyLink::with('farm')->where('party_id', $this->party()->id)->where('kind', $kind)->where('status', 'active')->get()
            ->filter(fn (PartyLink $l) => $l->farm !== null && $this->usable($l))
            ->sortBy(fn (PartyLink $l) => $l->farm->name)->values();
    }

    /** The active link of a kind to one farm, or 404. */
    public function linkTo(string $kind, string $farmId): PartyLink
    {
        return $this->links($kind)->first(fn (PartyLink $l) => $l->farm_id === $farmId) ?? throw ApiException::notFound();
    }

    /**
     * Run a callback in every linked farm's context and collect the results.
     *
     * @template T
     *
     * @param  Closure(PartyLink): T  $callback
     * @return array<int, T>
     */
    public function eachFarm(string $kind, Closure $callback): array
    {
        $results = [];
        foreach ($this->links($kind) as $link) {
            $results[] = $this->tenant->run($link->farm, fn () => $callback($link));
        }

        return $results;
    }

    private function usable(PartyLink $link): bool
    {
        if (! $link->farm->status->allowsMemberAccess()) {
            return false;
        }
        try {
            $this->subscriptions->assertFarmUsable($link->farm);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
