<?php

namespace App\Modules\Workforce\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Workforce\Domain\Enums\TaskStatus;
use App\Modules\Workforce\Domain\Enums\WorkerStatus;
use App\Modules\Workforce\Domain\Models\Worker;
use App\Support\Database\Codes;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Worker profiles. A profile may be linked to a farm member (who then sees
 * their tasks in the app); daily rates are money and need
 * finance.values.view to set.
 */
class Workers
{
    private const AUDITED = ['full_name', 'phone', 'job_title', 'employment_type', 'farm_user_id', 'status', 'started_on', 'left_on'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly WorkforceAccess $access,
        private readonly TenantContext $context,
    ) {}

    public function create(array $data): Worker
    {
        $this->guardMoney($data);
        $this->guardMembership($data['farm_user_id'] ?? null, null);

        return DB::transaction(function () use ($data) {
            $worker = Worker::create($data + [
                'worker_code' => Codes::next(Worker::class, 'WRK', 'worker_code'),
                'created_by' => Auth::id(),
            ]);
            $this->audit->record('workforce.worker.created', $worker, null, array_intersect_key($worker->toArray(), array_flip(self::AUDITED)));

            return $worker->refresh();
        });
    }

    public function update(Worker $worker, array $data): Worker
    {
        $this->guardMoney($data);
        if (array_key_exists('farm_user_id', $data)) {
            $this->guardMembership($data['farm_user_id'], $worker);
        }
        if (($data['status'] ?? null) === WorkerStatus::Inactive->value
            && $worker->tasks()->whereIn('status', [...TaskStatus::OPEN, TaskStatus::Submitted->value])->exists()) {
            throw ApiException::conflict('worker_has_open_tasks', "{$worker->full_name} still has open tasks. Cancel or reassign them first.");
        }

        $old = array_intersect_key($worker->toArray(), array_flip(self::AUDITED));
        $worker->fill($data)->save();
        $new = array_intersect_key($worker->toArray(), array_flip(self::AUDITED));
        $changed = array_keys(array_diff_assoc(array_map('strval', $new), array_map('strval', $old)));
        if ($changed || array_key_exists('daily_rate', $data)) {
            // The rate itself is money, so only the fact of its change is audited.
            $this->audit->record('workforce.worker.updated', $worker, array_intersect_key($old, array_flip($changed)), array_intersect_key($new, array_flip($changed))
                + (array_key_exists('daily_rate', $data) ? ['daily_rate' => 'changed'] : []));
        }

        return $worker;
    }

    private function guardMoney(array $data): void
    {
        if (array_key_exists('daily_rate', $data) && $data['daily_rate'] !== null && ! $this->access->seesMoney()) {
            throw ApiException::forbidden('money_field_forbidden', 'You cannot record money values.');
        }
    }

    private function guardMembership(?string $membershipId, ?Worker $worker): void
    {
        if ($membershipId === null) {
            return;
        }
        // Memberships are not farm-scoped models, so the farm is checked here.
        if (! FarmUser::whereKey($membershipId)->where('farm_id', $this->context->farmId())->exists()) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['farm_user_id' => ['The selected member does not belong to this farm.']]);
        }
        $taken = Worker::where('farm_user_id', $membershipId)->when($worker, fn ($q) => $q->whereKeyNot($worker->id))->first();
        if ($taken) {
            throw ApiException::conflict('duplicate', "That member is already linked to {$taken->worker_code}.");
        }
    }
}
