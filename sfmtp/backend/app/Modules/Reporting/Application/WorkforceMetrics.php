<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Tenancy\TenantContext;
use App\Modules\Workforce\Application\WorkforceAccess;
use App\Modules\Workforce\Domain\Enums\ActivityStatus;
use App\Modules\Workforce\Domain\Enums\LeaveStatus;
use App\Modules\Workforce\Domain\Enums\TaskStatus;
use App\Modules\Workforce\Domain\Enums\WorkerStatus;
use App\Modules\Workforce\Domain\Models\Attendance;
use App\Modules\Workforce\Domain\Models\Leave;
use App\Modules\Workforce\Domain\Models\Task;
use App\Modules\Workforce\Domain\Models\Worker;
use Carbon\CarbonImmutable;

/**
 * Workforce metrics for the manager, worker and owner dashboards
 * (docs/05 §3.2, §3.8). Task figures follow the member's task visibility,
 * so an agronomist counts crop work and a field worker their own tasks.
 */
class WorkforceMetrics
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly WorkforceAccess $access,
    ) {}

    public function tasksToday(): int
    {
        return $this->access->tasks()->whereNot('status', TaskStatus::Cancelled->value)->whereDate('due_on', $this->today())->count();
    }

    public function tasksVerified(Period $p): int
    {
        return $this->access->tasks()->where('status', TaskStatus::Verified->value)->whereBetween('verified_at', [$p->from, $p->to])->count();
    }

    public function tasksAwaitingReview(): int
    {
        return $this->access->tasks()->where('status', TaskStatus::Submitted->value)->count();
    }

    public function tasksOverdue(): int
    {
        return $this->access->tasks()->whereIn('status', TaskStatus::OPEN)->whereDate('due_on', '<', $this->today())->count();
    }

    public function activitiesOpen(): int
    {
        return $this->access->activities()->where('status', ActivityStatus::Open->value)->count();
    }

    public function workersPresent(): int
    {
        return Attendance::whereDate('work_date', $this->today())->count();
    }

    /** Active workers who have not checked in today and are not on leave. */
    public function workersAbsent(): int
    {
        $today = $this->today();

        return Worker::where('status', WorkerStatus::Active->value)
            ->whereNotIn('id', Attendance::whereDate('work_date', $today)->select('worker_id'))
            ->whereNotIn('id', Leave::where('status', LeaveStatus::Approved->value)->whereDate('from_on', '<=', $today)->whereDate('to_on', '>=', $today)->select('worker_id'))
            ->count();
    }

    public function workersActive(): int
    {
        return Worker::where('status', WorkerStatus::Active->value)->count();
    }

    // The signed-in worker's own figures.

    public function myTasksToday(): int
    {
        $me = $this->access->currentWorker();

        return $me ? Task::where('worker_id', $me->id)->where(fn ($q) => $q->whereIn('status', TaskStatus::OPEN)->where(fn ($q) => $q->whereNull('due_on')->orWhereDate('due_on', '<=', $this->today())))->count() : 0;
    }

    public function myDoneToday(): int
    {
        $me = $this->access->currentWorker();
        [$from, $to] = $this->dayBounds();

        return $me ? Task::where('worker_id', $me->id)->whereIn('status', [TaskStatus::Submitted->value, TaskStatus::Verified->value])->whereBetween('submitted_at', [$from, $to])->count() : 0;
    }

    /** "Checked in at 07:12", "Checked out", or "Not checked in". */
    public function myAttendanceStatus(): ?string
    {
        $me = $this->access->currentWorker();
        if (! $me) {
            return null;
        }
        $a = Attendance::where('worker_id', $me->id)->whereDate('work_date', $this->today())->first();
        $tz = $this->context->farm()->timezone;

        return match (true) {
            $a === null => 'Not checked in',
            $a->check_out_at !== null => 'Checked out at '.$a->check_out_at->setTimezone($tz)->format('H:i'),
            default => 'Checked in at '.$a->check_in_at->setTimezone($tz)->format('H:i'),
        };
    }

    // Widgets.

    /** Submitted tasks waiting for the member's verification. */
    public function verificationQueue(): array
    {
        return $this->access->tasks()->with(['activity', 'worker'])->where('status', TaskStatus::Submitted->value)
            ->orderBy('submitted_at')->limit(10)->get()
            ->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => "{$t->code} {$t->activity->title}",
                'subtitle' => $t->worker->full_name.($t->quantity !== null ? " · {$this->number($t->quantity)} {$t->unit}" : '').($t->worked_minutes ? ' · '.$this->duration($t->worked_minutes) : ''),
                'at' => $t->submitted_at?->toIso8601ZuluString(),
                'badge' => ['label' => 'To verify', 'tone' => 'warning'],
                'href' => "/farms/{$t->farm_id}/tasks/{$t->id}",
            ])->values()->all();
    }

    public function overdueTasks(): array
    {
        return $this->access->tasks()->with(['activity', 'worker'])->whereIn('status', TaskStatus::OPEN)->whereDate('due_on', '<', $this->today())
            ->orderBy('due_on')->limit(10)->get()
            ->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => "{$t->code} {$t->activity->title}",
                'subtitle' => $t->worker->full_name.' · '.str_replace('_', ' ', $t->status->value),
                'at' => $t->due_on->toDateString().'T12:00:00Z',
                'badge' => ['label' => 'Overdue', 'tone' => 'danger'],
                'href' => "/farms/{$t->farm_id}/tasks/{$t->id}",
            ])->values()->all();
    }

    /** Today's work, one line per task. */
    public function schedule(): array
    {
        return $this->access->tasks()->with(['activity', 'worker'])->whereNot('status', TaskStatus::Cancelled->value)->whereDate('due_on', $this->today())
            ->orderBy('status')->orderBy('code')->limit(15)->get()
            ->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => "{$t->worker->full_name}: {$t->activity->title}",
                'subtitle' => $t->code,
                'at' => null,
                'badge' => $this->statusBadge($t->status),
                'href' => "/farms/{$t->farm_id}/tasks/{$t->id}",
            ])->values()->all();
    }

    public function leaveRequests(): array
    {
        return Leave::with('worker')->where('status', LeaveStatus::Requested->value)->orderBy('from_on')->limit(10)->get()
            ->map(fn (Leave $l) => [
                'id' => $l->id,
                'title' => "{$l->worker->full_name}: ".ucfirst($l->kind->value).' leave',
                'subtitle' => $l->from_on->toDateString().' to '.$l->to_on->toDateString()." ({$l->days()} days)",
                'at' => $l->created_at?->toIso8601ZuluString(),
                'badge' => ['label' => 'Requested', 'tone' => 'info'],
                'href' => "/farms/{$l->farm_id}/workers?tab=leave",
            ])->values()->all();
    }

    /** The signed-in worker's tasks for today. */
    public function myTasks(): array
    {
        $me = $this->access->currentWorker();
        if (! $me) {
            return [];
        }

        return Task::with('activity')->where('worker_id', $me->id)
            ->where(fn ($q) => $q->whereIn('status', [...TaskStatus::OPEN, TaskStatus::Submitted->value])->where(fn ($q) => $q->whereNull('due_on')->orWhereDate('due_on', '<=', $this->today())))
            ->orderBy('due_on')->limit(15)->get()
            ->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => $t->activity->title,
                'subtitle' => $t->code.($t->activity->subject_label ? " · {$t->activity->subject_label}" : ''),
                'at' => null,
                'badge' => $this->statusBadge($t->status),
                'href' => "/farms/{$t->farm_id}/tasks/{$t->id}",
            ])->values()->all();
    }

    /** @return array{labels: array<int,string>, verified: array<int,int>} tasks verified per day */
    public function verifiedPerDay(Period $p): array
    {
        $tz = $this->context->farm()->timezone;
        $counts = [];
        foreach ($this->access->tasks()->where('status', TaskStatus::Verified->value)->whereBetween('verified_at', [$p->from, $p->to])->pluck('verified_at') as $at) {
            $day = CarbonImmutable::parse($at)->setTimezone($tz)->toDateString();
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }
        $labels = [];
        $values = [];
        for ($d = $p->from->setTimezone($tz)->startOfDay(); $d->lessThanOrEqualTo($p->to); $d = $d->addDay()) {
            $labels[] = $d->toDateString();
            $values[] = $counts[$d->toDateString()] ?? 0;
        }

        return ['labels' => $labels, 'verified' => $values];
    }

    /** @return array{labels: array<int,string>, hours: array<int,float>} the worker's hours for the last 7 days */
    public function myWeek(): array
    {
        $me = $this->access->currentWorker();
        $from = $this->today()->subDays(6);
        $rows = $me ? Attendance::where('worker_id', $me->id)->whereDate('work_date', '>=', $from)->get()->keyBy(fn ($a) => $a->work_date->toDateString()) : collect();
        $labels = [];
        $hours = [];
        for ($d = $from; $d->lessThanOrEqualTo($this->today()); $d = $d->addDay()) {
            $labels[] = $d->toDateString();
            $a = $rows->get($d->toDateString());
            $hours[] = $a && $a->minutes() !== null ? round($a->minutes() / 60, 1) : 0.0;
        }

        return ['labels' => $labels, 'hours' => $hours];
    }

    private function statusBadge(TaskStatus $status): array
    {
        return match ($status) {
            TaskStatus::Assigned => ['label' => 'To do', 'tone' => 'neutral'],
            TaskStatus::InProgress => ['label' => 'In progress', 'tone' => 'info'],
            TaskStatus::Paused => ['label' => 'Paused', 'tone' => 'warning'],
            TaskStatus::Submitted => ['label' => 'Submitted', 'tone' => 'info'],
            TaskStatus::Verified => ['label' => 'Verified', 'tone' => 'success'],
            TaskStatus::Rejected => ['label' => 'Redo', 'tone' => 'danger'],
            TaskStatus::Cancelled => ['label' => 'Cancelled', 'tone' => 'neutral'],
        };
    }

    private function today(): string
    {
        return CarbonImmutable::now($this->context->farm()->timezone)->toDateString();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} today in the farm's zone, as UTC instants */
    private function dayBounds(): array
    {
        $start = CarbonImmutable::now($this->context->farm()->timezone)->startOfDay();

        return [$start->utc(), $start->endOfDay()->utc()];
    }

    private function number(mixed $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
    }

    private function duration(int $minutes): string
    {
        return $minutes >= 60 ? intdiv($minutes, 60).' h '.($minutes % 60).' min' : "{$minutes} min";
    }
}
