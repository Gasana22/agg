<?php

namespace App\Modules\Sync\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Access\Application\ScopedAccess;
use App\Modules\Access\Domain\Enums\PermissionScope;
use App\Modules\Crops\Domain\Enums\CycleStage;
use App\Modules\Crops\Domain\Models\CropCycle;
use App\Modules\Crops\Http\Resources\CycleResource;
use App\Modules\FarmStructure\Domain\Models\Plot;
use App\Modules\FarmStructure\Http\Resources\StructureNodeResource;
use App\Modules\Livestock\Domain\Enums\AnimalStatus;
use App\Modules\Livestock\Domain\Models\Animal;
use App\Modules\Livestock\Domain\Models\AnimalGroup;
use App\Modules\Livestock\Http\Resources\AnimalResource;
use App\Modules\Livestock\Http\Resources\GroupResource;
use App\Modules\Notifications\Domain\Models\MemberNotification;
use App\Modules\Sync\Domain\Models\SyncConflict;
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
use Illuminate\Support\Facades\Auth;

/**
 * What a phone mirrors (docs/08 §2, "Scope of the mirror"), by role:
 * - a worker: their own tasks for the coming days plus recent ones, their
 *   attendance, leave and worker profile;
 * - crop staff: plots and open crop cycles;
 * - livestock staff: active groups and animals they may see;
 * - supervisors: tasks waiting for verification (`team_tasks`);
 * - everyone: their notifications and open sync conflicts.
 * Records are serialised by the same API resources as the web, so money
 * and other workers' locations never reach the phone.
 */
class SyncEntities
{
    public const ENTITIES = ['tasks', 'attendance', 'leave', 'workers', 'plots', 'crop_cycles', 'animal_groups', 'animals', 'team_tasks', 'notifications', 'conflicts'];

    private const WORKER_ENTITIES = ['tasks', 'attendance', 'leave', 'workers'];

    public function __construct(
        private readonly WorkforceAccess $access,
        private readonly FarmPermissions $permissions,
        private readonly ScopedAccess $scoped,
        private readonly TenantContext $context,
    ) {}

    /**
     * The entities this member mirrors.
     *
     * @param  array<int, string>  $wanted
     * @return array<int, string>
     */
    public function allowed(array $wanted): array
    {
        // Worker entities stay in the feed without a worker profile, so an
        // unlinked profile's records are removed from the phone.
        return array_values(array_filter($wanted, fn (string $e) => match ($e) {
            'tasks', 'attendance', 'leave', 'workers' => true,
            'plots' => $this->permissions->scope('structure.view') === PermissionScope::All,
            'crop_cycles' => $this->permissions->allows('crops.plans.view'),
            'animal_groups', 'animals' => $this->permissions->allows('livestock.animals.view'),
            'team_tasks' => $this->permissions->allows('tasks.verify'),
            'notifications', 'conflicts' => true,
            default => false,
        }));
    }

    /** The visible records of an entity, optionally limited to some ids. */
    public function query(string $entity, ?array $ids = null): ?Builder
    {
        if ($this->allowed([$entity]) === []) {
            return null;
        }
        $window = (int) config('sfmtp.sync.task_window_days');
        $today = CarbonImmutable::now($this->context->farm()->timezone)->startOfDay();
        $worker = in_array($entity, self::WORKER_ENTITIES, true) ? $this->access->currentWorker() : null;
        if (in_array($entity, self::WORKER_ENTITIES, true) && ($worker === null || ($entity === 'tasks' && ! $this->permissions->allows('tasks.view')))) {
            return null;
        }

        $query = match ($entity) {
            'tasks' => Task::with(TaskController::WITH)->where('worker_id', $worker->id)
                ->where(fn ($q) => $q->whereIn('status', [...TaskStatus::OPEN, TaskStatus::Submitted->value])->orWhere('updated_at', '>=', $today->subDays($window)))
                ->where(fn ($q) => $q->whereNull('due_on')->orWhereDate('due_on', '<=', $today->addDays($window))),
            'attendance' => Attendance::where('worker_id', $worker->id)->whereDate('work_date', '>=', $today->subDays($window)),
            'leave' => Leave::where('worker_id', $worker->id)->whereDate('to_on', '>=', $today->subDays(30))
                ->whereIn('status', [LeaveStatus::Requested->value, LeaveStatus::Approved->value, LeaveStatus::Rejected->value, LeaveStatus::Cancelled->value]),
            'workers' => Worker::whereKey($worker->id),
            'plots' => Plot::query(),
            'crop_cycles' => CropCycle::with(['plot', 'crop', 'plan', 'season', 'harvests', 'cropLot', 'nurseryBatch', 'seedBatch'])->where('stage', '!=', CycleStage::Closed->value),
            'animal_groups' => AnimalGroup::with('location')->withCount('activeAnimals')->where('is_active', true),
            'animals' => $this->scoped->scoped(Animal::with(['group', 'location', 'dam', 'sire', 'batch']), 'livestock.animals.view', 'created_by')
                ->where('status', AnimalStatus::Active->value),
            // Waiting for verification, in the modules this supervisor sees.
            'team_tasks' => $this->access->tasks()->with(TaskController::WITH)->where('status', TaskStatus::Submitted->value),
            'notifications' => MemberNotification::where('user_id', Auth::id())->where('created_at', '>=', $today->subDays(30)),
            'conflicts' => SyncConflict::where('user_id', Auth::id())->where('status', 'open'),
            default => null,
        };

        return $query && $ids !== null ? $query->whereIn($query->getModel()->getQualifiedKeyName(), $ids) : $query;
    }

    /** @return array{id:string, version:int|null, data:array<string,mixed>} */
    public function present(string $entity, $model, Request $request): array
    {
        $data = match ($entity) {
            'tasks', 'team_tasks' => (new TaskResource($model))->resolve($request),
            'attendance' => (new AttendanceResource($model))->resolve($request),
            'leave' => (new LeaveResource($model))->resolve($request),
            'workers' => (new WorkerResource($model))->resolve($request),
            'plots' => (new StructureNodeResource($model))->resolve($request),
            'crop_cycles' => (new CycleResource($model))->resolve($request),
            'animal_groups' => (new GroupResource($model))->resolve($request),
            'animals' => (new AnimalResource($model))->resolve($request),
            'notifications' => $model->toArrayForMember(),
            'conflicts' => $model->toArrayForMember(),
        };

        return ['id' => $model->getKey(), 'version' => isset($model->version) ? (int) $model->version : null, 'data' => $data];
    }
}
