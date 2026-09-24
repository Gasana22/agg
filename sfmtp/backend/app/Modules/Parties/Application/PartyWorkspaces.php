<?php

namespace App\Modules\Parties\Application;

use App\Modules\Access\Contracts\WorkspaceContributor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Parties\Domain\Models\Party;

/**
 * Adds the supplier and customer portals to GET /me/workspaces: one per
 * party and kind, listing the farms it deals with. A farm member with a
 * portal account sees both, with one sign-in (docs/01 §5).
 */
class PartyWorkspaces implements WorkspaceContributor
{
    public function __construct(private readonly PartyContext $parties) {}

    public function contribute(User $user, array $workspaces): array
    {
        $parties = Party::whereHas('users', fn ($q) => $q->whereKey($user->id))->where('status', 'active')->orderBy('name')->get();
        foreach ($parties as $party) {
            $this->parties->enter($party);
            try {
                foreach (['supplier', 'customer'] as $kind) {
                    $links = $this->parties->links($kind);
                    if ($links->isEmpty()) {
                        continue;
                    }
                    $workspaces[] = [
                        'type' => $kind,
                        'id' => $party->id,
                        'name' => $party->name,
                        'farms' => $links->map(fn ($l) => ['id' => $l->farm->id, 'name' => $l->farm->name])->values()->all(),
                        'dashboards' => [$kind],
                    ];
                }
            } finally {
                $this->parties->leave();
            }
        }

        return $workspaces;
    }
}
