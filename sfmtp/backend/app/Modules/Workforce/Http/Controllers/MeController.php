<?php

namespace App\Modules\Workforce\Http\Controllers;

use App\Modules\Tenancy\TenantContext;
use App\Modules\Workforce\Application\WorkforceAccess;
use App\Modules\Workforce\Domain\Enums\LeaveStatus;
use App\Modules\Workforce\Domain\Enums\TaskStatus;
use App\Modules\Workforce\Domain\Models\Attendance;
use App\Modules\Workforce\Domain\Models\Leave;
use App\Modules\Workforce\Domain\Models\Task;
use App\Modules\Workforce\Http\Resources\AttendanceResource;
use App\Modules\Workforce\Http\Resources\LeaveResource;
use App\Modules\Workforce\Http\Resources\TaskResource;
use App\Modules\Workforce\Http\Resources\WorkerResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * The field worker's home screen in one call (docs/06 §3): today's tasks,
 * today's attendance and upcoming leave. The phone shows the same from its
 * local database when offline.
 */
class MeController
{
    public function __construct(private readonly WorkforceAccess $access, private readonly TenantContext $context) {}

    public function today(): JsonResponse
    {
        $worker = $this->access->currentWorker();
        $today = CarbonImmutable::now($this->context->farm()->timezone)->toDateString();
        if (! $worker) {
            return response()->json(['data' => ['worker' => null, 'date' => $today, 'tasks' => [], 'attendance' => null, 'leave' => [], 'counts' => ['open' => 0, 'done_today' => 0]]]);
        }

        $tasks = Task::with(TaskController::WITH)->where('worker_id', $worker->id)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereIn('status', [...TaskStatus::OPEN, TaskStatus::Submitted->value])->where(fn ($q) => $q->whereNull('due_on')->orWhereDate('due_on', '<=', $today)))
                ->orWhere(fn ($q) => $q->whereIn('status', [TaskStatus::Verified->value, TaskStatus::Submitted->value])->whereDate('submitted_at', $today)))
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'paused' THEN 1 WHEN 'rejected' THEN 2 WHEN 'assigned' THEN 3 ELSE 4 END")
            ->orderBy('due_on')->orderBy('code')->get();
        $attendance = Attendance::where('worker_id', $worker->id)->whereDate('work_date', $today)->first();
        $leave = Leave::where('worker_id', $worker->id)->whereIn('status', [LeaveStatus::Requested->value, LeaveStatus::Approved->value])
            ->whereDate('to_on', '>=', $today)->orderBy('from_on')->limit(5)->get();

        return response()->json(['data' => [
            'worker' => new WorkerResource($worker),
            'date' => $today,
            'tasks' => TaskResource::collection($tasks),
            'attendance' => $attendance ? new AttendanceResource($attendance) : null,
            'leave' => LeaveResource::collection($leave),
            'counts' => [
                'open' => $tasks->filter(fn ($t) => in_array($t->status->value, TaskStatus::OPEN, true))->count(),
                'done_today' => $tasks->filter(fn ($t) => in_array($t->status, [TaskStatus::Submitted, TaskStatus::Verified], true) && $t->submitted_at?->setTimezone($this->context->farm()->timezone)->toDateString() === $today)->count(),
            ],
        ]]);
    }
}
