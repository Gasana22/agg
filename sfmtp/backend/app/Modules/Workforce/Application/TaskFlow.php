<?php

namespace App\Modules\Workforce\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Workforce\Domain\Enums\SubjectType;
use App\Modules\Workforce\Domain\Enums\TaskEvent;
use App\Modules\Workforce\Domain\Enums\TaskStatus;
use App\Modules\Workforce\Domain\Models\Task;
use App\Modules\Workforce\Domain\Models\TaskLog;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Runs the task state machine (TaskStatus). Every step writes a task log
 * with the time and place it happened; offline steps carry the phone's time.
 *
 * - The assigned worker starts, pauses, resumes and submits their task.
 * - A supervisor (tasks.verify) verifies or rejects it; nobody verifies
 *   their own work.
 * - A planner (tasks.manage) cancels it.
 *
 * Verifying work on a traceable subject (a crop cycle's lot, an animal)
 * adds a `work_done` event to its trace history.
 */
class TaskFlow
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly WorkforceAccess $access,
        private readonly Activities $activities,
        private readonly WorkSubjects $subjects,
        private readonly Recorder $recorder,
    ) {}

    /**
     * A worker's own step: start, pause, resume, submit or note.
     *
     * @param  array{id?:string, occurred_at?:string|\DateTimeInterface, lat?:float|null, lng?:float|null, accuracy_m?:float|null, quantity?:float|string|null, unit?:string|null, note?:string|null, device_id?:string|null}  $ctx
     */
    public function workerStep(Task $task, TaskEvent $event, array $ctx = []): Task
    {
        if (! in_array($event->value, TaskEvent::WORKER, true)) {
            throw ApiException::forbidden();
        }
        $this->assertOwnTask($task);
        $at = $this->occurredAt($ctx['occurred_at'] ?? null);

        return DB::transaction(function () use ($task, $event, $ctx, $at) {
            $task = Task::lockForUpdate()->findOrFail($task->id);
            $this->assertTransition($task, $event);
            $from = $task->status;
            $this->log($task, $event, $from, $at, $ctx);

            match ($event) {
                TaskEvent::Start => $task->forceFill(['status' => TaskStatus::InProgress, 'started_at' => $task->started_at ?? $at]),
                TaskEvent::Pause => $task->forceFill(['status' => TaskStatus::Paused]),
                TaskEvent::Resume => $task->forceFill(['status' => TaskStatus::InProgress]),
                TaskEvent::Submit => $task->forceFill([
                    'status' => TaskStatus::Submitted,
                    'submitted_at' => $at,
                    'quantity' => $ctx['quantity'] ?? $task->quantity,
                    'unit' => $ctx['unit'] ?? $task->unit,
                    'submit_note' => $ctx['note'] ?? null,
                    'worked_minutes' => $this->workedMinutes($task),
                ]),
                TaskEvent::Note => $task->forceFill(isset($ctx['quantity']) ? ['quantity' => $ctx['quantity'], 'unit' => $ctx['unit'] ?? $task->unit] : []),
                default => null,
            };
            // A note changes nothing, but the version still moves so phones pull the task again.
            $task->isDirty() ? $task->save() : $task->touch();

            return $task;
        });
    }

    /** Keep a refused offline step as evidence (docs/08 §4) without changing the task. */
    public function recordRefused(Task $task, TaskEvent $event, array $ctx = []): TaskLog
    {
        $this->assertOwnTask($task);

        return $this->log($task, $event, $task->status, $this->occurredAt($ctx['occurred_at'] ?? null, strict: false), $ctx, applied: false);
    }

    public function verify(Task $task, ?string $note): Task
    {
        return $this->review($task, TaskEvent::Verify, $note);
    }

    public function reject(Task $task, string $reason): Task
    {
        return $this->review($task, TaskEvent::Reject, $reason);
    }

    public function cancel(Task $task, string $reason, bool $rollup = true): Task
    {
        $this->access->visibleTask($task);
        $this->access->assertModuleVisible($task->activity->module);

        return DB::transaction(function () use ($task, $reason, $rollup) {
            $task = Task::lockForUpdate()->findOrFail($task->id);
            $this->assertTransition($task, TaskEvent::Cancel);
            $from = $task->status;
            $this->log($task, TaskEvent::Cancel, $from, CarbonImmutable::now(), ['note' => $reason]);
            $task->forceFill(['status' => TaskStatus::Cancelled, 'review_note' => $reason])->save();
            $this->audit->record('workforce.task.cancelled', $task, ['status' => $from->value], ['status' => 'cancelled', 'reason' => $reason]);
            if ($rollup) {
                $this->activities->rollup($task->activity);
            }

            return $task;
        });
    }

    private function review(Task $task, TaskEvent $event, ?string $note): Task
    {
        $this->access->visibleTask($task);
        $this->access->assertModuleVisible($task->activity->module);
        if ($task->worker->membership?->user_id === Auth::id()) {
            throw ApiException::forbidden('four_eyes', 'Someone else must verify your own work.');
        }

        return DB::transaction(function () use ($task, $event, $note) {
            $task = Task::with(['activity', 'worker'])->lockForUpdate()->findOrFail($task->id);
            $this->assertTransition($task, $event);
            $now = CarbonImmutable::now();
            $this->log($task, $event, $task->status, $now, ['note' => $note]);
            $verified = $event === TaskEvent::Verify;
            $task->forceFill([
                'status' => $verified ? TaskStatus::Verified : TaskStatus::Rejected,
                'verified_by' => $verified ? Auth::id() : null,
                'verified_at' => $verified ? $now : null,
                'review_note' => $note,
            ])->save();
            $this->audit->record('workforce.task.'.($verified ? 'verified' : 'rejected'), $task, ['status' => 'submitted'], ['status' => $task->status->value, 'note' => $note]);
            if ($verified) {
                $this->traceWork($task);
            }
            $this->activities->rollup($task->activity);

            return $task;
        });
    }

    private function traceWork(Task $task): void
    {
        $activity = $task->activity;
        if ($activity->subject_type === SubjectType::General) {
            return;
        }
        $batchId = $this->subjects->resolve($activity->subject_type, $activity->subject_id)->traceBatchId;
        $batch = $batchId ? TraceBatch::find($batchId) : null;
        if (! $batch) {
            return;
        }
        $submit = $task->logs()->where('event', TaskEvent::Submit->value)->where('applied', true)->latest('occurred_at')->first();
        $this->recorder->record($batch, 'work_done', [
            'occurred_at' => $task->submitted_at ?? now(),
            'worker_id' => $task->worker_id,
            'plot_id' => $activity->plot_id,
            'latitude' => $submit?->lat,
            'longitude' => $submit?->lng,
            'gps_accuracy_m' => $submit?->accuracy_m,
            'subject_type' => 'worker_task',
            'subject_id' => $task->id,
            'payload' => array_filter([
                'activity' => $activity->code,
                'activity_type' => $activity->type?->code,
                'task' => $task->code,
                'worker' => $task->worker->worker_code,
                'quantity' => $task->quantity,
                'unit' => $task->unit,
                'worked_minutes' => $task->worked_minutes,
            ], fn ($v) => $v !== null),
        ]);
    }

    private function assertOwnTask(Task $task): void
    {
        $worker = $this->access->requireWorker();
        if ($task->worker_id !== $worker->id) {
            throw ApiException::notFound();
        }
    }

    private function assertTransition(Task $task, TaskEvent $event): void
    {
        if (! in_array($task->status, $event->allowedFrom(), true)) {
            throw new ApiException(409, 'invalid_state_transition', "You cannot {$event->value} a task that is ".str_replace('_', ' ', $task->status->value).'.', [
                'status' => $task->status->value,
                'version' => $task->version,
            ]);
        }
    }

    private function occurredAt(string|\DateTimeInterface|null $value, bool $strict = true): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        if ($value === null) {
            return $now;
        }
        $at = CarbonImmutable::parse($value)->utc();
        if ($at->greaterThan($now->addSeconds(config('sfmtp.sync.max_clock_skew_seconds')))) {
            if ($strict) {
                throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['occurred_at' => ['The time is in the future. Check the phone\'s clock.']]);
            }

            return $now;
        }

        return $at;
    }

    private function log(Task $task, TaskEvent $event, TaskStatus $from, CarbonImmutable $at, array $ctx, bool $applied = true): TaskLog
    {
        return TaskLog::create([
            'id' => $ctx['id'] ?? null,
            'task_id' => $task->id,
            'event' => $event,
            'from_status' => $from->value,
            'to_status' => $applied ? $event->to()?->value : null,
            'applied' => $applied,
            'occurred_at' => $at,
            'lat' => $ctx['lat'] ?? null,
            'lng' => $ctx['lng'] ?? null,
            'accuracy_m' => $ctx['accuracy_m'] ?? null,
            'quantity' => $ctx['quantity'] ?? null,
            'unit' => $ctx['unit'] ?? null,
            'note' => $ctx['note'] ?? null,
            'device_id' => $ctx['device_id'] ?? null,
            'recorded_by' => Auth::id(),
        ]);
    }

    /** Time between each start / resume and the next pause / submit, in minutes. */
    private function workedMinutes(Task $task): int
    {
        $seconds = 0;
        $open = null;
        foreach ($task->logs()->where('applied', true)->get() as $log) {
            if (in_array($log->event, [TaskEvent::Start, TaskEvent::Resume], true)) {
                $open ??= $log->occurred_at;
            } elseif (in_array($log->event, [TaskEvent::Pause, TaskEvent::Submit], true) && $open) {
                $seconds += max(0, $open->diffInSeconds($log->occurred_at, false));
                $open = null;
            }
        }

        return (int) round($seconds / 60);
    }
}
