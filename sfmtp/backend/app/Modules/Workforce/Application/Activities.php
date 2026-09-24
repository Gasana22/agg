<?php

namespace App\Modules\Workforce\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Catalog\Domain\Models\ActivityType;
use App\Modules\Notifications\Application\Inbox;
use App\Modules\Workforce\Domain\Enums\ActivityStatus;
use App\Modules\Workforce\Domain\Enums\LeaveStatus;
use App\Modules\Workforce\Domain\Enums\SubjectType;
use App\Modules\Workforce\Domain\Enums\TaskStatus;
use App\Modules\Workforce\Domain\Models\Activity;
use App\Modules\Workforce\Domain\Models\Leave;
use App\Modules\Workforce\Domain\Models\Task;
use App\Modules\Workforce\Domain\Models\Worker;
use App\Support\Database\Codes;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Plans work: an activity on a subject, and one task per assigned worker.
 * The activity is completed when all its tasks are verified or cancelled.
 */
class Activities
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly WorkforceAccess $access,
        private readonly WorkSubjects $subjects,
    ) {}

    /** @param  array<string,mixed>  $data  including worker_ids */
    public function create(array $data): Activity
    {
        $type = ActivityType::where('is_active', true)->find($data['activity_type_id'])
            ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['activity_type_id' => ['Unknown activity type.']]);
        $this->access->assertModuleVisible($type->module);

        $subjectType = SubjectType::from($data['subject_type'] ?? SubjectType::General->value);
        $subject = $this->subjects->resolve($subjectType, $data['subject_id'] ?? null);
        if (! $subject->active) {
            throw ApiException::conflict('subject_inactive', "{$subject->label} is closed; no new work can be planned on it.");
        }
        if ($subject->module !== null && $type->module !== 'general' && $subject->module !== $type->module) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [
                'activity_type_id' => ["{$type->name} is {$type->module} work and cannot be done on a {$subjectType->value}."],
            ]);
        }

        $plannedOn = CarbonImmutable::parse($data['planned_on'] ?? now()->toDateString());
        $workers = $this->assignable($data['worker_ids'], $plannedOn);

        return DB::transaction(function () use ($data, $type, $subject, $subjectType, $plannedOn, $workers) {
            $activity = Activity::create([
                'code' => Codes::next(Activity::class, 'ACT'),
                'activity_type_id' => $type->id,
                'module' => $type->module,
                'title' => $data['title'] ?? trim($type->name.($subject->id ? " · {$subject->label}" : '')),
                'instructions' => $data['instructions'] ?? null,
                'subject_type' => $subjectType->value,
                'subject_id' => $subject->id,
                'subject_label' => $subject->id ? mb_substr($subject->label, 0, 150) : null,
                'plot_id' => $subject->plotId,
                'location_id' => $subject->locationId,
                'planned_on' => $plannedOn->toDateString(),
                'due_on' => $data['due_on'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'target_quantity' => $data['target_quantity'] ?? null,
                'target_unit' => $data['target_unit'] ?? null,
                'created_by' => Auth::id(),
            ]);
            $tasks = $this->createTasks($activity, $workers);
            $this->audit->record('workforce.activity.created', $activity, null, [
                'code' => $activity->code, 'type' => $type->code, 'subject' => $subject->label, 'tasks' => $tasks,
            ]);

            return $activity->refresh();
        });
    }

    public function update(Activity $activity, array $data): Activity
    {
        $this->assertOpen($activity);
        $this->access->assertModuleVisible($activity->module);
        if (isset($data['due_on']) && CarbonImmutable::parse($data['due_on'])->lessThan($activity->planned_on)) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['due_on' => ['The due date cannot be before the planned date.']]);
        }

        return DB::transaction(function () use ($activity, $data) {
            $old = array_intersect_key($activity->only(array_keys($data)), $data);
            $activity->fill($data)->save();
            if (array_key_exists('due_on', $data)) {
                // Tasks follow the activity's due date unless they are done.
                $activity->tasks()->whereNotIn('status', ['verified', 'cancelled'])->get()->each(fn (Task $t) => $t->forceFill(['due_on' => $data['due_on']])->save());
            }
            $this->audit->record('workforce.activity.updated', $activity, $old, $data);

            return $activity;
        });
    }

    /** Add workers to an open activity. */
    public function assign(Activity $activity, array $workerIds): Activity
    {
        $this->assertOpen($activity);
        $this->access->assertModuleVisible($activity->module);
        $workers = $this->assignable($workerIds, $activity->planned_on->toImmutable());
        $already = $activity->tasks()->whereNot('status', TaskStatus::Cancelled->value)->pluck('worker_id')->all();
        $new = array_values(array_filter($workers, fn (Worker $w) => ! in_array($w->id, $already, true)));
        if ($new === []) {
            throw ApiException::conflict('duplicate', 'Those workers already have this task.');
        }

        return DB::transaction(function () use ($activity, $new) {
            $tasks = $this->createTasks($activity, $new);
            $this->audit->record('workforce.activity.assigned', $activity, null, ['tasks' => $tasks]);

            return $activity;
        });
    }

    /** Cancel the activity and every task still being worked on. */
    public function cancel(Activity $activity, string $reason, TaskFlow $flow): Activity
    {
        $this->assertOpen($activity);
        $this->access->assertModuleVisible($activity->module);
        if ($activity->tasks()->where('status', TaskStatus::Submitted->value)->exists()) {
            throw ApiException::conflict('tasks_awaiting_review', 'Some tasks are waiting for verification. Verify or reject them first.');
        }

        return DB::transaction(function () use ($activity, $reason, $flow) {
            foreach ($activity->tasks()->whereIn('status', TaskStatus::OPEN)->get() as $task) {
                $flow->cancel($task, $reason, rollup: false);
            }
            $this->rollup($activity->refresh());
            $this->audit->record('workforce.activity.cancelled', $activity, ['status' => 'open'], ['status' => $activity->status->value, 'reason' => $reason]);

            return $activity;
        });
    }

    /** Recompute the activity's status from its tasks. */
    public function rollup(Activity $activity): void
    {
        $statuses = $activity->tasks()->pluck('status')->map(fn ($s) => $s instanceof TaskStatus ? $s->value : $s)->all();
        $finished = $statuses !== [] && array_diff($statuses, ['verified', 'cancelled']) === [];
        $status = match (true) {
            ! $finished => ActivityStatus::Open,
            in_array('verified', $statuses, true) => ActivityStatus::Completed,
            default => ActivityStatus::Cancelled,
        };
        if ($status !== $activity->status) {
            $activity->forceFill(['status' => $status, 'completed_at' => $status === ActivityStatus::Completed ? now() : null])->save();
        }
    }

    private function assertOpen(Activity $activity): void
    {
        if ($activity->status !== ActivityStatus::Open) {
            throw ApiException::conflict('invalid_state_transition', "{$activity->code} is {$activity->status->value}.");
        }
    }

    /**
     * Active workers of this farm who are not on approved leave that day.
     *
     * @param  array<int,string>  $ids
     * @return array<int, Worker>
     */
    private function assignable(array $ids, CarbonImmutable $day): array
    {
        $ids = array_values(array_unique($ids));
        $workers = Worker::whereIn('id', $ids)->get()->keyBy('id');
        $errors = [];
        foreach ($ids as $i => $id) {
            $worker = $workers->get($id);
            if (! $worker) {
                $errors["worker_ids.{$i}"] = ['The selected worker does not exist in this farm.'];
            } elseif (! $worker->isActive()) {
                $errors["worker_ids.{$i}"] = ["{$worker->full_name} is inactive."];
            }
        }
        if ($errors) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', $errors);
        }

        $onLeave = Leave::whereIn('worker_id', $ids)->where('status', LeaveStatus::Approved->value)
            ->whereDate('from_on', '<=', $day)->whereDate('to_on', '>=', $day)->with('worker')->first();
        if ($onLeave) {
            throw ApiException::conflict('worker_on_leave', "{$onLeave->worker->full_name} is on leave from {$onLeave->from_on->toDateString()} to {$onLeave->to_on->toDateString()}.");
        }

        return array_map(fn ($id) => $workers->get($id), $ids);
    }

    /**
     * @param  array<int, Worker>  $workers
     * @return array<int,string> the new task codes
     */
    private function createTasks(Activity $activity, array $workers): array
    {
        $codes = [];
        foreach ($workers as $worker) {
            $task = Task::create([
                'code' => Codes::next(Task::class, 'TSK'),
                'activity_id' => $activity->id,
                'worker_id' => $worker->id,
                'assigned_by' => Auth::id(),
                'due_on' => ($activity->due_on ?? $activity->planned_on)->toDateString(),
            ]);
            $codes[] = $task->code;
            if ($userId = $worker->loadMissing('membership')->membership?->user_id) {
                app(Inbox::class)->notify([$userId], 'task_assigned', "New task: {$activity->title}",
                    $task->due_on ? 'Due '.$task->due_on->toFormattedDateString() : null, "/farms/{$activity->farm_id}/my-day", ['task_id' => $task->id]);
            }
        }
        if ($activity->status !== ActivityStatus::Open) {
            $activity->forceFill(['status' => ActivityStatus::Open, 'completed_at' => null])->save();
        }

        return $codes;
    }
}
