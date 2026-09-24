<?php

namespace App\Modules\Workforce\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Access\Domain\Enums\PermissionScope;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Workforce\Domain\Models\Activity;
use App\Modules\Workforce\Domain\Models\Attendance;
use App\Modules\Workforce\Domain\Models\Leave;
use App\Modules\Workforce\Domain\Models\Task;
use App\Modules\Workforce\Domain\Models\Worker;
use App\Support\Http\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Who sees which workforce records (docs/04 §2, "Tasks & activities").
 *
 * - `all` scopes reach every record of the modules the member can see: crop
 *   work needs a crops permission, animal work livestock, and so on, so an
 *   agronomist manages crop tasks and a livestock manager animal tasks.
 *   General work is visible to all of them.
 * - `assigned` reaches the member's own tasks (through their worker profile).
 * - `own` reaches what the member created, plus their own tasks.
 * - Daily rates are money (finance.values.view).
 */
class WorkforceAccess
{
    /** Permissions that open each activity module. */
    private const MODULE_PERMISSIONS = [
        'crops' => ['crops.plans.view', 'crops.operations.view', 'crops.harvest.view'],
        'livestock' => ['livestock.animals.view'],
        'assets' => ['assets.view'],
        'inventory' => ['inventory.view'],
        'general' => [],
    ];

    public function __construct(
        private readonly FarmPermissions $permissions,
        private readonly TenantContext $context,
    ) {}

    public function can(string $permission): bool
    {
        return $this->permissions->allows($permission);
    }

    public function scope(string $permission): ?PermissionScope
    {
        return $this->permissions->scope($permission);
    }

    public function seesMoney(): bool
    {
        return $this->permissions->allows('finance.values.view');
    }

    /** The signed-in member's worker profile, if they have one. */
    public function currentWorker(): ?Worker
    {
        $membership = $this->context->membership();
        if (! $membership) {
            return null;
        }

        return $this->context->remember('workforce.worker', fn () => Worker::where('farm_user_id', $membership->id)->first());
    }

    /** @return array<int,string> */
    public function visibleModules(): array
    {
        return $this->context->remember('workforce.modules', fn () => array_keys(array_filter(
            self::MODULE_PERMISSIONS,
            fn (array $perms) => $perms === [] || array_filter($perms, fn ($p) => $this->permissions->allows($p)) !== [],
        )));
    }

    public function assertModuleVisible(string $module): void
    {
        if (! in_array($module, $this->visibleModules(), true)) {
            throw ApiException::forbidden('module_forbidden', "You cannot manage {$module} work.");
        }
    }

    /** Tasks the member may see. */
    public function tasks(): Builder
    {
        $query = Task::query();
        $mine = $this->currentWorker()?->id;

        return match ($this->scope('tasks.view')) {
            PermissionScope::All => $query->whereHas('activity', fn ($q) => $q->whereIn('module', $this->visibleModules())),
            PermissionScope::Assigned => $mine ? $query->where('worker_id', $mine) : $query->whereRaw('1 = 0'),
            PermissionScope::Own => $query->where(fn ($q) => $q->where('assigned_by', Auth::id())->when($mine, fn ($q) => $q->orWhere('worker_id', $mine))),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Activities the member may see: the module rule, or those holding one of their visible tasks. */
    public function activities(): Builder
    {
        $query = Activity::query();

        return match ($this->scope('tasks.view')) {
            PermissionScope::All => $query->whereIn('module', $this->visibleModules()),
            null => $query->whereRaw('1 = 0'),
            default => $query->where(fn ($q) => $q->where('created_by', Auth::id())
                ->orWhereIn('id', $this->tasks()->select('activity_id'))),
        };
    }

    public function workers(): Builder
    {
        $query = Worker::query();
        $mine = $this->currentWorker()?->id;

        return match ($this->scope('workers.view')) {
            PermissionScope::All => $query,
            null => $query->whereRaw('1 = 0'),
            default => $mine ? $query->whereKey($mine) : $query->whereRaw('1 = 0'),
        };
    }

    public function attendance(): Builder
    {
        $query = Attendance::query();
        $mine = $this->currentWorker()?->id;

        return match ($this->scope('attendance.view')) {
            PermissionScope::All => $query,
            null => $query->whereRaw('1 = 0'),
            default => $mine ? $query->where('worker_id', $mine) : $query->whereRaw('1 = 0'),
        };
    }

    /** Leave: approvers and full attendance viewers see all; others their own. */
    public function leave(): Builder
    {
        $query = Leave::query();
        if ($this->can('leave.approve') || $this->scope('attendance.view') === PermissionScope::All) {
            return $query;
        }
        $mine = $this->currentWorker()?->id;

        return $mine ? $query->where('worker_id', $mine) : $query->whereRaw('1 = 0');
    }

    /** The task, if the member may see it; otherwise 404 (docs/06 §2). */
    public function visibleTask(Task $task): Task
    {
        if (! $this->tasks()->whereKey($task->id)->exists()) {
            throw ApiException::notFound();
        }

        return $task;
    }

    public function visibleActivity(Activity $activity): Activity
    {
        if (! $this->activities()->whereKey($activity->id)->exists()) {
            throw ApiException::notFound();
        }

        return $activity;
    }

    public function visibleWorker(Worker $worker): Worker
    {
        if (! $this->workers()->whereKey($worker->id)->exists()) {
            throw ApiException::notFound();
        }

        return $worker;
    }

    /** The member's own worker profile; needed to execute tasks, check in or ask for leave. */
    public function requireWorker(): Worker
    {
        $worker = $this->currentWorker();
        if (! $worker) {
            throw ApiException::forbidden('no_worker_profile', 'You do not have a worker profile on this farm. Ask your manager to link one.');
        }
        if (! $worker->isActive()) {
            throw ApiException::forbidden('worker_inactive', 'Your worker profile is inactive.');
        }

        return $worker;
    }

    /** A photo the member uploaded; one still on its way is MediaNotReady (the sync API waits for it). */
    public function assertOwnMedia(?string $mediaId): void
    {
        if ($mediaId === null) {
            return;
        }
        $media = Media::find($mediaId) ?? throw new MediaNotReady($mediaId);
        if ($media->uploaded_by !== Auth::id()) {
            throw ApiException::forbidden('media_forbidden', 'You can only attach photos you took.');
        }
    }
}
