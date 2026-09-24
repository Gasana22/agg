<?php

namespace App\Modules\Sync\Application;

use App\Modules\Tenancy\TenantContext;
use App\Modules\Workforce\Application\WorkforceAccess;
use App\Modules\Workforce\Domain\Enums\LeaveStatus;
use App\Modules\Workforce\Domain\Enums\TaskStatus;
use App\Modules\Workforce\Domain\Models\Attendance;
use App\Modules\Workforce\Domain\Models\Leave;
use App\Modules\Workforce\Domain\Models\Task;
use App\Modules\Workforce\Domain\Models\Worker;
use App\Modules\Workforce\Http\Controllers\TaskController;
use App\Modules\Workforce\Http\Resources\AttendanceResource;
use App\Modules\Workforce\Http\Resources\LeaveResource;
use App\Modules\Workforce\Http\Resources\TaskResource;
use App\Modules\Workforce\Http\Resources\WorkerResource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * What a phone mirrors (docs/08 §2, "Scope of the mirror"). The field-worker
 * app keeps the member's own tasks for the coming days plus recent ones,
 * their recent attendance and leave, and their worker profile. Records are
 * serialised by the same API resources as the web, so money and other
 * workers' locations never reach the phone.
 */
class SyncEntities
{
    public const ENTITIES = ['tasks', 'attendance', 'leave', 'workers'];

    public function __construct(
        private readonly WorkforceAccess $access,
        private readonly TenantContext $context,
    ) {}

    /** The visible records of an entity, optionally limited to some ids. */
    public function query(string $entity, ?array $ids = null): ?Builder
    {
        $worker = $this->access->currentWorker();
        if (! $worker) {
            return null;
        }
        $window = (int) config('sfmtp.sync.task_window_days');
        $today = CarbonImmutable::now($this->context->farm()->timezone)->startOfDay();

        $query = match ($entity) {
            'tasks' => $this->access->can('tasks.view') ? Task::with(TaskController::WITH)->where('worker_id', $worker->id)
                ->where(fn ($q) => $q->whereIn('status', [...TaskStatus::OPEN, TaskStatus::Submitted->value])->orWhere('updated_at', '>=', $today->subDays($window)))
                ->where(fn ($q) => $q->whereNull('due_on')->orWhereDate('due_on', '<=', $today->addDays($window))) : null,
            'attendance' => Attendance::where('worker_id', $worker->id)->whereDate('work_date', '>=', $today->subDays($window)),
            'leave' => Leave::where('worker_id', $worker->id)->whereDate('to_on', '>=', $today->subDays(30))
                ->whereIn('status', [LeaveStatus::Requested->value, LeaveStatus::Approved->value, LeaveStatus::Rejected->value, LeaveStatus::Cancelled->value]),
            'workers' => Worker::whereKey($worker->id),
            default => null,
        };

        return $query && $ids !== null ? $query->whereIn($query->getModel()->getQualifiedKeyName(), $ids) : $query;
    }

    /** @return array{id:string, version:int, data:array<string,mixed>} */
    public function present(string $entity, $model, Request $request): array
    {
        $resource = match ($entity) {
            'tasks' => new TaskResource($model),
            'attendance' => new AttendanceResource($model),
            'leave' => new LeaveResource($model),
            'workers' => new WorkerResource($model),
        };

        return ['id' => $model->getKey(), 'version' => (int) $model->version, 'data' => $resource->resolve($request)];
    }
}
