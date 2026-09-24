<?php

namespace App\Modules\Workforce\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Workforce\Domain\Enums\LeaveStatus;
use App\Modules\Workforce\Domain\Enums\TaskStatus;
use App\Modules\Workforce\Domain\Models\Leave;
use App\Modules\Workforce\Domain\Models\Task;
use App\Modules\Workforce\Domain\Models\Worker;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\Auth;

/**
 * Leave requests. A worker asks for their own leave; an approver may also
 * record leave for any worker. Nobody decides their own request unless they
 * are the owner. Overlapping requested or approved leave is refused.
 */
class LeaveDesk
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly WorkforceAccess $access,
        private readonly TenantContext $context,
    ) {}

    public function request(array $data): Leave
    {
        if (isset($data['worker_id']) && $data['worker_id'] !== $this->access->currentWorker()?->id) {
            if (! $this->access->can('leave.approve')) {
                throw ApiException::forbidden();
            }
            $worker = Worker::find($data['worker_id']) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['worker_id' => ['The selected worker does not exist in this farm.']]);
        } else {
            if (! $this->access->can('leave.request') && ! $this->access->can('leave.approve')) {
                throw ApiException::forbidden();
            }
            $worker = $this->access->requireWorker();
        }

        $overlap = Leave::where('worker_id', $worker->id)
            ->whereIn('status', [LeaveStatus::Requested->value, LeaveStatus::Approved->value])
            ->whereDate('from_on', '<=', $data['to_on'])->whereDate('to_on', '>=', $data['from_on'])
            ->first();
        if ($overlap) {
            if (isset($data['id']) && $overlap->id === $data['id']) {
                return $overlap;
            }
            throw ApiException::conflict('overlapping_leave', "There is already {$overlap->status->value} leave from {$overlap->from_on->toDateString()} to {$overlap->to_on->toDateString()}.");
        }

        $leave = Leave::create([
            'id' => $data['id'] ?? null,
            'worker_id' => $worker->id,
            'kind' => $data['kind'],
            'from_on' => $data['from_on'],
            'to_on' => $data['to_on'],
            'reason' => $data['reason'] ?? null,
            'requested_by' => Auth::id(),
        ]);
        $this->audit->record('workforce.leave.requested', $leave, null, ['worker' => $worker->worker_code] + array_intersect_key($data, array_flip(['kind', 'from_on', 'to_on'])));

        return $leave->refresh();
    }

    public function decide(Leave $leave, bool $approve, ?string $note): Leave
    {
        if ($leave->status !== LeaveStatus::Requested) {
            throw ApiException::conflict('invalid_state_transition', "The request is {$leave->status->value}.");
        }
        $ownRequest = $leave->requested_by === Auth::id() || $leave->worker->membership?->user_id === Auth::id();
        if ($ownRequest && ! $this->context->membership()?->is_owner) {
            throw ApiException::forbidden('four_eyes', 'Someone else must decide your own leave.');
        }

        $status = $approve ? LeaveStatus::Approved : LeaveStatus::Rejected;
        $leave->forceFill(['status' => $status, 'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_note' => $note])->save();
        $this->audit->record('workforce.leave.'.($approve ? 'approved' : 'rejected'), $leave, ['status' => 'requested'], ['status' => $status->value, 'note' => $note]);

        return $leave;
    }

    public function cancel(Leave $leave): Leave
    {
        $mine = $leave->requested_by === Auth::id() || $leave->worker_id === $this->access->currentWorker()?->id;
        if (! $mine && ! $this->access->can('leave.approve')) {
            throw ApiException::notFound();
        }
        $cancellable = $leave->status === LeaveStatus::Requested
            || ($leave->status === LeaveStatus::Approved && $leave->from_on->isAfter(now()->setTimezone($this->context->farm()->timezone)->startOfDay()));
        if (! $cancellable) {
            throw ApiException::conflict('invalid_state_transition', 'Only requested leave, or approved leave that has not started, can be cancelled.');
        }
        $from = $leave->status->value;
        $leave->forceFill(['status' => LeaveStatus::Cancelled])->save();
        $this->audit->record('workforce.leave.cancelled', $leave, ['status' => $from], ['status' => 'cancelled']);

        return $leave;
    }

    /** Open tasks due during the leave, for the approver to reassign. */
    public function clashingTasks(Leave $leave): int
    {
        return Task::where('worker_id', $leave->worker_id)->whereIn('status', TaskStatus::OPEN)
            ->whereDate('due_on', '>=', $leave->from_on)->whereDate('due_on', '<=', $leave->to_on)->count();
    }
}
