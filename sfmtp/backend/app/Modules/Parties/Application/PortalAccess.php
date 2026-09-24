<?php

namespace App\Modules\Parties\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Enums\UserType;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Parties\Domain\Models\Party;
use App\Modules\Parties\Domain\Models\PartyLink;
use App\Modules\Parties\Domain\Models\PortalInvitation;
use App\Modules\Parties\Notifications\PortalInvitationSent;
use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Opening a farm's supplier or customer record to a portal (ADR-0016).
 *
 * The farm invites an email address for one record. Accepting links the
 * record to a party: the accepting person's party when they already have
 * exactly one, one they choose when they have several, or a new party named
 * after the record. A person without an account gets a `party` account; a
 * farm member keeps theirs and sees the portal as another workspace.
 * Unlinking stops portal access at once and keeps the history.
 */
class PortalAccess
{
    public const TTL_DAYS = 14;

    public function __construct(
        private readonly TenantContext $context,
        private readonly PortalSubjects $subjects,
        private readonly FarmPermissions $permissions,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{0: PortalInvitation, 1: string} the invitation and its one-time token */
    public function invite(string $kind, string $recordId, string $email, ?string $message = null): array
    {
        $subject = $this->subjects->get($kind);
        $this->assertCan($subject->managePermission());
        $record = $subject->find($recordId);
        if ($record === null || ! $record['is_active']) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['record_id' => ["Choose an active {$kind} of this farm."]]);
        }
        if ($this->activeLink($kind, $recordId)) {
            throw ApiException::conflict('already_linked', "{$record['name']} already has portal access. Unlink it first to invite someone else.");
        }
        $email = mb_strtolower(trim($email));
        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($kind, $recordId, $email, $message, $token, $record) {
            // One open invitation per record: a new one replaces the old link.
            PortalInvitation::pending()->where('kind', $kind)->where('record_id', $recordId)
                ->update(['revoked_at' => now(), 'revoked_by' => Auth::id()]);
            $invitation = PortalInvitation::create([
                'kind' => $kind,
                'record_id' => $recordId,
                'email' => $email,
                'token_hash' => PortalInvitation::hashToken($token),
                'message' => $message,
                'invited_by' => Auth::id(),
                'expires_at' => now()->addDays(self::TTL_DAYS),
            ]);
            $this->audit->record('portal.invitation.sent', $invitation, null, ['kind' => $kind, 'record' => $record['code'], 'email' => $email]);

            return $invitation;
        });

        $farm = $this->context->farm();
        Notification::route('mail', $email)->notify(new PortalInvitationSent(
            farmName: $farm->name,
            kind: $kind,
            recordName: $record['name'],
            inviterName: Auth::user()?->name ?? $farm->name,
            token: $token,
            expiresOn: $invitation->expires_at->timezone($farm->timezone)->toFormattedDayDateString(),
            note: $message,
        ));

        return [$invitation, $token];
    }

    public function revokeInvitation(PortalInvitation $invitation): PortalInvitation
    {
        $this->assertCan($this->subjects->get($invitation->kind)->managePermission());
        if ($invitation->status() !== 'pending') {
            throw ApiException::conflict('invalid_state_transition', "The invitation is {$invitation->status()}.");
        }
        $invitation->forceFill(['revoked_at' => now(), 'revoked_by' => Auth::id()])->save();
        $this->audit->record('portal.invitation.revoked', $invitation, null, ['email' => $invitation->email]);

        return $invitation;
    }

    /** Stop a party's access to one record. */
    public function unlink(PartyLink $link): PartyLink
    {
        if ($link->farm_id !== $this->context->farmId()) {
            throw ApiException::notFound();
        }
        $subject = $this->subjects->get($link->kind);
        $this->assertCan($subject->managePermission());
        if ($link->status !== 'active') {
            throw ApiException::conflict('invalid_state_transition', 'This portal access is already stopped.');
        }

        return DB::transaction(function () use ($link, $subject) {
            $link->forceFill(['status' => 'revoked', 'revoked_by' => Auth::id(), 'revoked_at' => now()])->save();
            $subject->attach($link->record_id, null);
            $this->audit->record('portal.link.revoked', $link, ['status' => 'active'], ['status' => 'revoked', 'kind' => $link->kind]);

            return $link;
        });
    }

    /** Look up an invitation from its emailed token, outside any farm context. */
    public function findByToken(string $token): PortalInvitation
    {
        $invitation = $this->context->bypass(fn () => PortalInvitation::with(['inviter', 'farm'])
            ->where('token_hash', PortalInvitation::hashToken($token))->first());
        if ($invitation === null || $invitation->farm === null || $invitation->farm->status === FarmStatus::Closed) {
            throw ApiException::notFound();
        }

        return $invitation;
    }

    /** @return array<string, mixed> what the invitation page shows before accepting */
    public function preview(string $token): array
    {
        $invitation = $this->findByToken($token);
        $record = $this->context->run($invitation->farm, fn () => $this->subjects->get($invitation->kind)->find($invitation->record_id));

        return [
            'type' => 'portal_invitation_preview',
            'status' => $invitation->status(),
            'kind' => $invitation->kind,
            'email' => $invitation->email,
            'farm' => ['id' => $invitation->farm->id, 'name' => $invitation->farm->name],
            'record_name' => $record['name'] ?? null,
            'invited_by' => $invitation->inviter?->name,
            'message' => $invitation->message,
            'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
            'account_exists' => User::whereRaw('LOWER(email) = ?', [$invitation->email])->exists(),
        ];
    }

    /**
     * @param  array{name?:string, password?:string, party_id?:string}  $input
     * @return array{0: PartyLink, 1: bool} the link and whether an account was created
     */
    public function accept(string $token, ?User $signedIn, array $input): array
    {
        $invitation = $this->findByToken($token);
        match ($invitation->status()) {
            'accepted' => throw new ApiException(410, 'invitation_used', 'This invitation has already been used.'),
            'revoked' => throw new ApiException(410, 'invitation_revoked', 'This invitation was withdrawn.'),
            'expired' => throw new ApiException(410, 'invitation_expired', 'This invitation has expired. Ask for a new one.'),
            default => null,
        };

        $existing = User::whereRaw('LOWER(email) = ?', [$invitation->email])->first();
        if ($signedIn !== null && mb_strtolower($signedIn->email) !== $invitation->email) {
            throw ApiException::forbidden('invitation_email_mismatch', 'This invitation was sent to a different email address. Sign in with that address.');
        }
        if ($existing !== null && $signedIn === null) {
            throw ApiException::conflict('sign_in_required', 'An account with this email already exists. Sign in to accept the invitation.');
        }
        if ($existing !== null && $existing->user_type === UserType::PlatformAdmin) {
            throw ApiException::forbidden('portal_account_not_allowed', 'Platform staff accounts cannot use the portals.');
        }
        if ($existing === null && (empty($input['name']) || empty($input['password']))) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', array_filter([
                'name' => empty($input['name']) ? ['Your name is required to create an account.'] : null,
                'password' => empty($input['password']) ? ['A password is required to create an account.'] : null,
            ]));
        }
        $party = $existing ? $this->partyFor($existing, $input['party_id'] ?? null) : null;

        return DB::transaction(function () use ($invitation, $existing, $input, $party) {
            $created = $existing === null;
            $user = $existing ?? User::create([
                'user_type' => UserType::Party->value,
                'name' => $input['name'],
                'email' => $invitation->email,
                'password' => $input['password'],
            ]);
            if ($created) {
                // The emailed link proves the address.
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $link = $this->context->run($invitation->farm, function () use ($invitation, $user, $party) {
                $subject = $this->subjects->get($invitation->kind);
                $record = $subject->find($invitation->record_id);
                if ($record === null || ! $record['is_active']) {
                    throw new ApiException(410, 'invitation_revoked', 'This invitation is no longer valid.');
                }
                if ($this->activeLink($invitation->kind, $invitation->record_id)) {
                    throw ApiException::conflict('already_linked', "{$record['name']} already has portal access.");
                }
                $party ??= $this->newParty($record, $invitation->email);
                if (! $party->hasUser($user->id)) {
                    $party->users()->attach($user->id, ['id' => (string) Str::uuid7(), 'created_at' => now()]);
                }
                $link = PartyLink::updateOrCreate(
                    ['farm_id' => $invitation->farm_id, 'kind' => $invitation->kind, 'record_id' => $invitation->record_id],
                    ['party_id' => $party->id, 'status' => 'active', 'linked_by' => $invitation->invited_by, 'linked_at' => now(), 'revoked_by' => null, 'revoked_at' => null],
                );
                $subject->attach($invitation->record_id, $party->id);
                $invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id, 'party_id' => $party->id])->save();
                $this->audit->record('portal.invitation.accepted', $invitation, null, ['kind' => $invitation->kind, 'party' => $party->name], ['user_id' => $user->id]);

                return $link;
            });

            return [$link->load('party', 'farm'), $created];
        });
    }

    /** @return array<int, array<string, mixed>> the farm's portal links and open invitations */
    public function overview(): array
    {
        $kinds = array_values(array_filter(array_keys($this->subjects->all()), fn ($k) => $this->canView($k)));
        $links = PartyLink::with('party')->where('farm_id', $this->context->farmId())->whereIn('kind', $kinds)
            ->orderByDesc('linked_at')->get();
        $invitations = PortalInvitation::with('inviter')->whereIn('kind', $kinds)->orderByDesc('created_at')->limit(200)->get();

        $rows = [];
        foreach ($links as $link) {
            $record = $this->subjects->get($link->kind)->find($link->record_id);
            $rows[] = [
                'type' => 'portal_link',
                'id' => $link->id,
                'kind' => $link->kind,
                'record_id' => $link->record_id,
                'record_name' => $record['name'] ?? null,
                'party' => ['id' => $link->party->id, 'name' => $link->party->name],
                'people' => $link->party->users()->count(),
                'status' => $link->status,
                'linked_at' => $link->linked_at->toIso8601ZuluString(),
                'revoked_at' => $link->revoked_at?->toIso8601ZuluString(),
            ];
        }
        foreach ($invitations as $invitation) {
            if ($invitation->status() === 'accepted') {
                continue; // shown as its link
            }
            $record = $this->subjects->get($invitation->kind)->find($invitation->record_id);
            $rows[] = [
                'type' => 'portal_invitation',
                'id' => $invitation->id,
                'kind' => $invitation->kind,
                'record_id' => $invitation->record_id,
                'record_name' => $record['name'] ?? null,
                'email' => $invitation->email,
                'invited_by' => $invitation->inviter?->name,
                'status' => $invitation->status(),
                'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
                'created_at' => $invitation->created_at?->toIso8601ZuluString(),
            ];
        }

        return $rows;
    }

    public function canView(string $kind): bool
    {
        return $this->permissions->allows($kind === 'supplier' ? 'suppliers.view' : 'customers.view')
            || $this->permissions->allows($this->subjects->get($kind)->managePermission());
    }

    private function partyFor(User $user, ?string $partyId): ?Party
    {
        $parties = Party::whereHas('users', fn ($q) => $q->whereKey($user->id))->get();
        if ($partyId !== null) {
            return $parties->firstWhere('id', $partyId)
                ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['party_id' => ['Choose one of your portal accounts.']]);
        }

        return match ($parties->count()) {
            0 => null,
            1 => $parties->first(),
            default => throw ApiException::unprocessable('party_choice_required', 'You have several portal accounts; choose which one this farm deals with.', [
                'party_id' => $parties->map(fn (Party $p) => "{$p->id}: {$p->name}")->all(),
            ]),
        };
    }

    private function newParty(array $record, string $email): Party
    {
        return Party::create(['name' => $record['name'], 'email' => $record['email'] ?? $email, 'status' => 'active']);
    }

    private function activeLink(string $kind, string $recordId): bool
    {
        return PartyLink::where('farm_id', $this->context->farmId())->where('kind', $kind)->where('record_id', $recordId)->where('status', 'active')->exists();
    }

    private function assertCan(string $permission): void
    {
        if (! $this->permissions->allows($permission)) {
            throw ApiException::forbidden();
        }
    }
}
