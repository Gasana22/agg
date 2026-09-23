<?php

namespace App\Modules\Access\Contracts;

use App\Modules\Identity\Domain\Models\User;

/**
 * Lets higher modules add to GET /me/workspaces without Access depending on
 * them: Platform adds the admin workspace, Billing adds subscription state to
 * farms, Support adds granted read-only farms. Tag implementations with
 * `sfmtp.workspace-contributors`.
 */
interface WorkspaceContributor
{
    /**
     * @param  array<int,array<string,mixed>>  $workspaces
     * @return array<int,array<string,mixed>>
     */
    public function contribute(User $user, array $workspaces): array;
}
