<?php

namespace App\Modules\Platform\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Platform\Domain\Models\FarmStatusChange;
use App\Modules\Platform\Notifications\FarmStatusChanged;
use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Platform-level farm lifecycle (docs/05 §3.1, docs/04 §5). Changes only a
 * farm's *status*, never its operational data.
 */
class FarmAdministration
{
    public const SUSPENSION_REASONS = ['non_payment', 'policy_violation', 'security', 'owner_request', 'other'];

    public function __construct(
        private readonly PlatformPermissions $permissions,
        private readonly AuditLogger $audit,
    ) {}

    public function approve(Farm $farm, User $actor): Farm
    {
        if ($farm->status !== FarmStatus::Pending) {
            throw ApiException::conflict('invalid_state_transition', 'Only pending farms can be approved.');
        }

        return $this->transition($farm, FarmStatus::Active, $actor, null, null, ['approved_at' => now(), 'approved_by' => $actor->id]);
    }

    public function suspend(Farm $farm, User $actor, string $reasonCode, ?string $note): Farm
    {
        if (! in_array($farm->status, [FarmStatus::Pending, FarmStatus::Active], true)) {
            throw ApiException::conflict('invalid_state_transition', 'Only pending or active farms can be suspended.');
        }
        $this->assertMaySuspendFor($actor, $reasonCode);

        return $this->transition($farm, FarmStatus::Suspended, $actor, $reasonCode, $note, [
            'suspended_at' => now(),
            'suspension_reason' => $reasonCode,
        ]);
    }

    public function unsuspend(Farm $farm, User $actor, ?string $note): Farm
    {
        if ($farm->status !== FarmStatus::Suspended) {
            throw ApiException::conflict('invalid_state_transition', 'This farm is not suspended.');
        }
        // Billing staff may only lift the suspensions they are allowed to impose.
        $this->assertMaySuspendFor($actor, $farm->suspension_reason ?? 'other');

        $to = $farm->approved_at ? FarmStatus::Active : FarmStatus::Pending;

        return $this->transition($farm, $to, $actor, null, $note, ['suspended_at' => null, 'suspension_reason' => null]);
    }

    /** Sends the owner a reset link. The admin never sees or sets the password (ADR-0006). */
    public function sendOwnerPasswordReset(Farm $farm): void
    {
        $owner = $this->owner($farm);
        Password::sendResetLink(['email' => $owner->email]);
        $this->audit->record('admin.owner_password_reset_sent', $farm, null, ['owner_user_id' => $owner->id]);
    }

    public function owner(Farm $farm): User
    {
        return FarmUser::with('user')->where('farm_id', $farm->id)->where('is_owner', true)->firstOrFail()->user;
    }

    private function assertMaySuspendFor(User $actor, string $reasonCode): void
    {
        if (! $this->permissions->allows($actor, 'farms.approve') && $reasonCode !== 'non_payment') {
            throw ApiException::forbidden('forbidden', 'Your platform role can only suspend farms for non-payment.');
        }
    }

    private function transition(Farm $farm, FarmStatus $to, User $actor, ?string $reasonCode, ?string $note, array $attributes): Farm
    {
        $from = $farm->status;

        DB::transaction(function () use ($farm, $from, $to, $actor, $reasonCode, $note, $attributes) {
            $farm->forceFill(['status' => $to] + $attributes)->save();
            FarmStatusChange::create([
                'farm_id' => $farm->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'reason_code' => $reasonCode,
                'note' => $note,
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);
            $this->audit->record('admin.farm_status_changed', $farm, ['status' => $from->value], array_filter([
                'status' => $to->value,
                'reason_code' => $reasonCode,
                'note' => $note,
            ]));
        });

        $this->owner($farm)->notify(new FarmStatusChanged($farm, $to->value, $note));

        return $farm;
    }
}
