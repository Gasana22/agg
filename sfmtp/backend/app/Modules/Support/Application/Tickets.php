<?php

namespace App\Modules\Support\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Support\Domain\Models\SupportMessage;
use App\Modules\Support\Domain\Models\SupportTicket;
use App\Modules\Tenancy\Domain\Enums\MembershipStatus;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Support\Http\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class Tickets
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Tickets a farm-side user may see: their own, plus every ticket of organizations they own. */
    public function visibleTo(User $user): Builder
    {
        return SupportTicket::query()->where(fn ($q) => $q
            ->where('opened_by', $user->id)
            ->orWhereIn('organization_id', Organization::where('owner_user_id', $user->id)->select('id')));
    }

    public function open(User $user, array $data): SupportTicket
    {
        [$organizationId, $farmId] = $this->resolveScope($user, $data['farm_id'] ?? null);

        return DB::transaction(function () use ($user, $data, $organizationId, $farmId) {
            $ticket = SupportTicket::create([
                'reference' => $this->reference(),
                'organization_id' => $organizationId,
                'farm_id' => $farmId,
                'opened_by' => $user->id,
                'subject' => $data['subject'],
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'open',
                'last_activity_at' => now(),
            ]);
            SupportMessage::create(['ticket_id' => $ticket->id, 'author_id' => $user->id, 'body' => $data['body'], 'is_internal' => false]);
            $this->audit->record('support.ticket_opened', $ticket, null, ['reference' => $ticket->reference], ['farm_id' => $farmId]);

            return $ticket;
        });
    }

    public function reply(SupportTicket $ticket, User $author, string $body, bool $internal, bool $byStaff): SupportMessage
    {
        if ($ticket->status === 'closed') {
            throw ApiException::conflict('invalid_state_transition', 'This ticket is closed. Open a new one.');
        }

        return DB::transaction(function () use ($ticket, $author, $body, $internal, $byStaff) {
            $message = SupportMessage::create(['ticket_id' => $ticket->id, 'author_id' => $author->id, 'body' => $body, 'is_internal' => $byStaff && $internal]);

            $attributes = ['last_activity_at' => now()];
            if (! $internal) {
                // A staff reply waits on the customer; a customer reply re-opens the ticket.
                $attributes['status'] = $byStaff ? 'pending' : 'open';
            }
            $ticket->forceFill($attributes)->save();

            return $message;
        });
    }

    public function update(SupportTicket $ticket, array $data, User $actor): SupportTicket
    {
        $before = $ticket->only(array_keys($data));
        $ticket->fill($data)->forceFill(['last_activity_at' => now()])->save();
        $this->audit->record('support.ticket_updated', $ticket, $before, $data, ['farm_id' => $ticket->farm_id]);

        return $ticket;
    }

    /** @return array{0:string,1:?string} organization id, farm id */
    private function resolveScope(User $user, ?string $farmId): array
    {
        if ($farmId !== null) {
            $isMember = FarmUser::where('farm_id', $farmId)->where('user_id', $user->id)->where('status', MembershipStatus::Active->value)->exists();
            if (! $isMember) {
                throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['farm_id' => ['You are not a member of this farm.']]);
            }

            return [Farm::whereKey($farmId)->value('organization_id'), $farmId];
        }

        $organizationId = Organization::where('owner_user_id', $user->id)->value('id')
            ?? Farm::whereIn('id', FarmUser::where('user_id', $user->id)->where('status', 'active')->select('farm_id'))->value('organization_id');

        if ($organizationId === null) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['farm_id' => ['Choose the farm this is about.']]);
        }

        return [$organizationId, null];
    }

    private function reference(): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
        do {
            $ref = 'SUP-';
            for ($i = 0; $i < 6; $i++) {
                $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (SupportTicket::where('reference', $ref)->exists());

        return $ref;
    }
}
