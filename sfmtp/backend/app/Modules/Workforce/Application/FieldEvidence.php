<?php

namespace App\Modules\Workforce\Application;

use App\Modules\Workforce\Domain\Enums\TaskStatus;
use App\Modules\Workforce\Domain\Models\GpsPoint;
use App\Modules\Workforce\Domain\Models\Task;
use App\Modules\Workforce\Domain\Models\TaskPhoto;
use App\Modules\Workforce\Domain\Models\Worker;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * Photos and GPS points from the worker's phone. Both are append-only.
 * Location is only accepted during a work session: while checked in, or
 * while one of the worker's tasks was in progress (docs/08 §5).
 */
class FieldEvidence
{
    public function __construct(
        private readonly WorkforceAccess $access,
        private readonly AttendanceBook $attendance,
    ) {}

    /** @param  array{id?:string, media_id:string, taken_at?:string, lat?:float, lng?:float, accuracy_m?:float, caption?:string}  $data */
    public function attachPhoto(Task $task, array $data): TaskPhoto
    {
        $worker = $this->access->requireWorker();
        if ($task->worker_id !== $worker->id) {
            throw ApiException::notFound();
        }
        if ($task->status->isFinal()) {
            throw ApiException::conflict('invalid_state_transition', "Photos cannot be added to a {$task->status->value} task.");
        }
        $this->access->assertOwnMedia($data['media_id']);
        if ($existing = TaskPhoto::where('task_id', $task->id)->where('media_id', $data['media_id'])->first()) {
            return $existing;
        }

        return TaskPhoto::create([
            'id' => $data['id'] ?? null,
            'task_id' => $task->id,
            'media_id' => $data['media_id'],
            'taken_at' => $data['taken_at'] ?? null,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'accuracy_m' => $data['accuracy_m'] ?? null,
            'caption' => $data['caption'] ?? null,
            'recorded_by' => Auth::id(),
        ])->refresh();
    }

    /**
     * @param  array<int, array{id?:string, recorded_at:string, lat:float, lng:float, accuracy_m?:float, task_id?:string}>  $points
     * @return array{accepted:int, skipped:int}
     */
    public function recordTrack(array $points, ?string $deviceId): array
    {
        $worker = $this->access->requireWorker();
        $accepted = 0;
        foreach ($points as $p) {
            $at = CarbonImmutable::parse($p['recorded_at'])->utc();
            if ($at->isFuture() || ! $this->inSession($worker, $at, $p['task_id'] ?? null)) {
                continue;
            }
            if (isset($p['id']) && GpsPoint::whereKey($p['id'])->exists()) {
                continue;
            }
            GpsPoint::create([
                'id' => $p['id'] ?? null,
                'worker_id' => $worker->id,
                'task_id' => $p['task_id'] ?? null,
                'recorded_at' => $at,
                'lat' => $p['lat'],
                'lng' => $p['lng'],
                'accuracy_m' => $p['accuracy_m'] ?? null,
                'device_id' => $deviceId,
            ]);
            $accepted++;
        }

        return ['accepted' => $accepted, 'skipped' => count($points) - $accepted];
    }

    private function inSession(Worker $worker, CarbonImmutable $at, ?string $taskId): bool
    {
        if ($this->attendance->onDuty($worker, $at)) {
            return true;
        }
        if (! $taskId) {
            return false;
        }
        $task = Task::where('worker_id', $worker->id)->find($taskId);
        if (! $task || ! $task->started_at || $at->lessThan($task->started_at)) {
            return false;
        }
        // Between the first start and the submit (or the cancellation).
        $end = $task->submitted_at ?? ($task->status === TaskStatus::Cancelled ? $task->updated_at : null);

        return $end === null || $at->lessThanOrEqualTo($end);
    }
}
