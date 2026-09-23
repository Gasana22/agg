<?php

namespace App\Modules\Support\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Platform\Application\PlatformPermissions;
use App\Modules\Support\Domain\Models\SupportAccessGrant;
use App\Modules\Support\Domain\Models\SupportTicket;
use App\Modules\Tenancy\Contracts\SupportAccessResolver;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Support\Http\ApiException;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADR-0005: the Farm Owner can grant platform support read-only access to one
 * farm, from a ticket, for up to 72 hours. Every request is audited.
 */
class SupportAccess implements SupportAccessResolver
{
    public const MAX_HOURS = 72;

    public function __construct(
        private readonly PlatformPermissions $platform,
        private readonly AuditLogger $audit,
    ) {}

    public function grant(SupportTicket $ticket, User $owner, int $hours): SupportAccessGrant
    {
        $this->assertOwner($ticket, $owner);
        if ($ticket->farm_id === null) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['ticket' => ['This ticket is not about a specific farm.']]);
        }
        if (in_array($ticket->status, ['resolved', 'closed'], true)) {
            throw ApiException::conflict('invalid_state_transition', 'Access can only be granted on an open ticket.');
        }

        $grant = SupportAccessGrant::create([
            'farm_id' => $ticket->farm_id,
            'ticket_id' => $ticket->id,
            'granted_by' => $owner->id,
            'grantee_user_id' => $ticket->assigned_to,
            'expires_at' => now()->addHours(min(max($hours, 1), self::MAX_HOURS)),
        ]);
        $this->audit->record('support.access_granted', $grant, null, [
            'ticket' => $ticket->reference, 'expires_at' => $grant->expires_at->toIso8601ZuluString(), 'grantee_user_id' => $grant->grantee_user_id,
        ], ['farm_id' => $ticket->farm_id]);

        return $grant;
    }

    public function revoke(SupportAccessGrant $grant, User $owner): SupportAccessGrant
    {
        $this->assertOwner(SupportTicket::findOrFail($grant->ticket_id), $owner);
        if ($grant->revoked_at === null) {
            $grant->forceFill(['revoked_at' => now(), 'revoked_by' => $owner->id])->save();
            $this->audit->record('support.access_revoked', $grant, null, null, ['farm_id' => $grant->farm_id]);
        }

        return $grant;
    }

    /** Revoke every open grant when a ticket is resolved or closed. */
    public function revokeForTicket(SupportTicket $ticket, User $actor): void
    {
        SupportAccessGrant::where('ticket_id', $ticket->id)->whereNull('revoked_at')->where('expires_at', '>', now())
            ->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);
    }

    public function activeGrantId(string $userId, string $farmId): ?string
    {
        $user = User::find($userId);
        if ($user === null || ! $this->platform->allows($user, 'support.access')) {
            return null;
        }

        return $this->activeGrants($farmId)
            ->where(fn ($q) => $q->whereNull('grantee_user_id')->orWhere('grantee_user_id', $userId))
            ->orderByDesc('expires_at')
            ->value('id');
    }

    public function activeGrantFor(string $farmId): ?SupportAccessGrant
    {
        return $this->activeGrants($farmId)->orderByDesc('expires_at')->first();
    }

    /** @return Builder<SupportAccessGrant> */
    public function activeGrants(?string $farmId = null)
    {
        return SupportAccessGrant::query()
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    private function assertOwner(SupportTicket $ticket, User $user): void
    {
        $ownerId = Organization::whereKey($ticket->organization_id)->value('owner_user_id');
        if ($ownerId !== $user->id) {
            throw ApiException::forbidden('owner_only', 'Only the farm owner can grant or revoke support access.');
        }
    }
}
