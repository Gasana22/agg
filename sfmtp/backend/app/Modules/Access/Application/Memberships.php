<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\Models\FarmInvitation;
use App\Modules\Access\Domain\Models\FarmRole;
use App\Modules\Access\Notifications\FarmInvitationSent;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Enums\UserType;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\FarmService;
use App\Modules\Tenancy\Contracts\SubscriptionGate;
use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\Domain\Enums\MembershipStatus;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Invitations and member management (docs/04 §3 "Users, roles & permissions").
 *
 * Members with `members.manage` invite anyone into any role but the owner's;
 * members with only `members.invite_workers` (the Farm Manager) invite into
 * field-worker roles, i.e. roles holding `worker.self`. The plan's user limit
 * is checked when inviting and again when the invitation is accepted.
 */
class Memberships
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FarmPermissions $permissions,
        private readonly FarmService $farms,
        private readonly RoleService $roles,
        private readonly SubscriptionGate $subscriptions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<int,string>  $roleIds
     * @return array{0: FarmInvitation, 1: string} the invitation and its one-time token
     */
    public function invite(User $inviter, string $email, array $roleIds, ?string $message = null): array
    {
        $farm = $this->context->farm();
        $email = mb_strtolower(trim($email));
        $roles = $this->invitableRoles($roleIds);

        $existing = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing !== null && $existing->user_type !== UserType::Member) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [
                'email' => ['This email belongs to an account that cannot join farms.'],
            ]);
        }
        if ($existing !== null && FarmUser::where('farm_id', $farm->id)->where('user_id', $existing->id)->exists()) {
            throw ApiException::conflict('duplicate', 'This person is already a member of the farm.');
        }
        if (FarmInvitation::pending()->where('email', $email)->exists()) {
            throw ApiException::conflict('duplicate', 'This person already has a pending invitation. Resend it instead.');
        }

        $this->subscriptions->assertCanAddMember($farm, $existing?->id ?? (string) Str::uuid7());

        $token = Str::random(48);
        $invitation = DB::transaction(function () use ($farm, $inviter, $email, $roles, $message, $token) {
            $invitation = FarmInvitation::create([
                'email' => $email,
                'token_hash' => FarmInvitation::hashToken($token),
                'message' => $message,
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(config('sfmtp.invitations.ttl_days')),
                'last_sent_at' => now(),
            ]);
            DB::table('farm_invitation_roles')->insert($roles->map(fn (FarmRole $r) => [
                'farm_id' => $farm->id,
                'invitation_id' => $invitation->id,
                'farm_role_id' => $r->id,
            ])->all());

            $this->audit->record('member.invited', $invitation, null, ['email' => $email, 'roles' => $roles->pluck('key')->all()]);

            return $invitation;
        });

        $this->send($invitation, $inviter, $token);

        return [$invitation->load('roles'), $token];
    }

    /** @return array{0: FarmInvitation, 1: string} */
    public function resend(FarmInvitation $invitation, User $by): array
    {
        $this->assertMayHandle($invitation);
        if (in_array($invitation->status(), ['accepted', 'revoked'], true)) {
            throw ApiException::conflict('invalid_state_transition', "This invitation is {$invitation->status()}.");
        }
        if ($invitation->send_count >= config('sfmtp.invitations.max_resends') + 1) {
            throw ApiException::conflict('resend_limit_reached', 'This invitation has been sent too many times. Revoke it and invite again.');
        }

        // A new token: links in earlier emails stop working.
        $token = Str::random(48);
        $invitation->forceFill([
            'token_hash' => FarmInvitation::hashToken($token),
            'expires_at' => now()->addDays(config('sfmtp.invitations.ttl_days')),
            'last_sent_at' => now(),
            'send_count' => $invitation->send_count + 1,
        ])->save();

        $this->audit->record('member.invitation_resent', $invitation, null, ['email' => $invitation->email]);
        $this->send($invitation, $by, $token);

        return [$invitation, $token];
    }

    public function revoke(FarmInvitation $invitation, User $by): void
    {
        $this->assertMayHandle($invitation);
        if ($invitation->status() === 'accepted') {
            throw ApiException::conflict('invalid_state_transition', 'This invitation was already accepted. Remove the member instead.');
        }
        if ($invitation->revoked_at !== null) {
            return;
        }

        $invitation->forceFill(['revoked_at' => now(), 'revoked_by' => $by->id])->save();
        $this->audit->record('member.invitation_revoked', $invitation, null, ['email' => $invitation->email]);
    }

    /** Look up an invitation from its emailed token, outside any farm context. */
    public function findByToken(string $token): FarmInvitation
    {
        $invitation = $this->context->bypass(fn () => FarmInvitation::with(['roles', 'inviter', 'farm'])
            ->where('token_hash', FarmInvitation::hashToken($token))
            ->first());

        if ($invitation === null || $invitation->farm->status === FarmStatus::Closed) {
            throw ApiException::notFound();
        }

        return $invitation;
    }

    /**
     * Accept an invitation, either as the signed-in account with the invited
     * email, or by creating that account.
     *
     * @param  array{name?:string, password?:string}  $newAccount
     * @return array{0: FarmUser, 1: bool} the membership and whether an account was created
     */
    public function accept(string $token, ?User $signedIn, array $newAccount): array
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
        if ($existing !== null && $existing->user_type !== UserType::Member) {
            throw ApiException::forbidden('member_account_required', 'This account cannot join farms.');
        }
        if ($existing === null && (empty($newAccount['name']) || empty($newAccount['password']))) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', array_filter([
                'name' => empty($newAccount['name']) ? ['Your name is required to create an account.'] : null,
                'password' => empty($newAccount['password']) ? ['A password is required to create an account.'] : null,
            ]));
        }

        return DB::transaction(function () use ($invitation, $existing, $newAccount) {
            $created = $existing === null;
            $user = $existing;
            if ($created) {
                $user = User::create([
                    'user_type' => UserType::Member->value,
                    'name' => $newAccount['name'],
                    'email' => $invitation->email,
                    'password' => $newAccount['password'],
                ]);
                // The emailed link proves the address.
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $membership = $this->context->run($invitation->farm, function () use ($invitation, $user) {
                $membership = $this->farms->addMember($invitation->farm, $user, $invitation->invited_by);
                foreach ($invitation->roles as $role) {
                    $this->roles->assignRole($membership, $role);
                }
                $invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
                $this->audit->record('member.invitation_accepted', $invitation, null, ['email' => $invitation->email], ['user_id' => $user->id]);

                return $membership;
            });

            return [$membership, $created];
        });
    }

    /**
     * Change a member's roles and/or status.
     *
     * @param  array{role_ids?: array<int,string>, status?: string}  $changes
     */
    public function update(FarmUser $member, User $by, array $changes): FarmUser
    {
        $this->assertManageable($member, $by);

        return DB::transaction(function () use ($member, $changes) {
            if (isset($changes['role_ids'])) {
                $roles = $this->invitableRoles($changes['role_ids']);
                $this->roles->replaceMemberRoles($member, $roles);
            }

            if (isset($changes['status']) && $changes['status'] !== $member->status->value) {
                if ($changes['status'] === MembershipStatus::Active->value) {
                    $this->subscriptions->assertCanAddMember($this->context->farm(), $member->user_id);
                }
                $before = $member->status->value;
                $member->forceFill(['status' => $changes['status']])->save();
                $this->audit->record('member.status_changed', $member, ['status' => $before], ['status' => $changes['status']]);
            }

            return $member->refresh();
        });
    }

    /** Remove someone from the farm. Their past records keep their name. */
    public function remove(FarmUser $member, User $by): void
    {
        $this->assertManageable($member, $by);

        $this->audit->record('member.removed', $member, [
            'user_id' => $member->user_id,
            'roles' => $this->roleKeys($member),
        ], null);
        $member->delete();   // role assignments cascade
    }

    /** @return array<int,string> */
    public function roleKeys(FarmUser $member): array
    {
        return DB::table('farm_user_roles')
            ->join('farm_roles', 'farm_roles.id', '=', 'farm_user_roles.farm_role_id')
            ->where('farm_user_roles.farm_user_id', $member->id)
            ->orderBy('farm_roles.key')
            ->pluck('farm_roles.key')
            ->all();
    }

    /**
     * The roles the caller may hand out, or a 403 / 422.
     *
     * @param  array<int,string>  $roleIds
     * @return Collection<int,FarmRole>
     */
    private function invitableRoles(array $roleIds): Collection
    {
        $roles = FarmRole::with('permissions')->whereIn('id', $roleIds)->get();
        if ($roles->count() !== count(array_unique($roleIds)) || $roles->isEmpty()) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [
                'role_ids' => ['Choose at least one role of this farm.'],
            ]);
        }
        if ($roles->contains(fn (FarmRole $r) => $r->key === RoleTemplates::OWNER)) {
            throw ApiException::unprocessable('guard_rail_owner_role', 'The owner role can only be held by the farm owner. Use ownership transfer instead.');
        }

        if (! $this->permissions->allows('members.manage')) {
            $onlyWorkers = $roles->every(fn (FarmRole $r) => $r->permissions->contains('key', 'worker.self'));
            if (! $this->permissions->allows('members.invite_workers') || ! $onlyWorkers) {
                throw ApiException::forbidden('forbidden', 'You can only invite field workers.');
            }
        }

        return $roles;
    }

    private function assertMayHandle(FarmInvitation $invitation): void
    {
        if (! $this->permissions->allows('members.manage')) {
            $this->invitableRoles($invitation->roles()->pluck('farm_roles.id')->all());
        }
    }

    private function assertManageable(FarmUser $member, User $by): void
    {
        if ($member->is_owner) {
            throw ApiException::forbidden('guard_rail_owner', 'The farm owner cannot be changed or removed. Use ownership transfer instead.');
        }
        if ($member->user_id === $by->id) {
            throw ApiException::forbidden('guard_rail_self', 'You cannot change your own membership.');
        }
    }

    private function send(FarmInvitation $invitation, User $inviter, string $token): void
    {
        Notification::route('mail', $invitation->email)->notify(new FarmInvitationSent(
            farmName: $this->context->farm()->name,
            inviterName: $inviter->name,
            token: $token,
            expiresOn: $invitation->expires_at->timezone($this->context->farm()->timezone)->toFormattedDayDateString(),
            note: $invitation->message,
        ));
    }
}
