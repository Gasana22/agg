<?php

namespace App\Modules\Workforce\Http\Controllers;

use App\Modules\Workforce\Application\FieldEvidence;
use App\Modules\Workforce\Application\TaskFlow;
use App\Modules\Workforce\Application\WorkforceAccess;
use App\Modules\Workforce\Domain\Enums\TaskEvent;
use App\Modules\Workforce\Domain\Enums\TaskStatus;
use App\Modules\Workforce\Domain\Models\Task;
use App\Modules\Workforce\Http\Resources\TaskResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskController
{
    public const WITH = ['activity.type', 'worker', 'verifier'];

    public function __construct(
        private readonly TaskFlow $flow,
        private readonly FieldEvidence $evidence,
        private readonly WorkforceAccess $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', 'string', 'max:200'],
            'filter.worker_id' => ['sometimes', 'uuid'],
            'filter.activity_id' => ['sometimes', 'uuid'],
            'filter.module' => ['sometimes', 'string', 'max:20'],
            'filter.due_from' => ['sometimes', 'date'],
            'filter.due_to' => ['sometimes', 'date'],
            'filter.overdue' => ['sometimes', 'boolean'],
            'filter.mine' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $f = $data['filter'] ?? [];
        $statuses = isset($f['status']) ? array_values(array_intersect(explode(',', $f['status']), TaskStatus::values())) : null;
        $bool = fn (string $k) => isset($f[$k]) && filter_var($f[$k], FILTER_VALIDATE_BOOLEAN);

        return TaskResource::collection($this->access->tasks()->with(self::WITH)
            ->when($statuses !== null, fn ($q) => $q->whereIn('status', $statuses))
            ->when($f['worker_id'] ?? null, fn ($q, $v) => $q->where('worker_id', $v))
            ->when($f['activity_id'] ?? null, fn ($q, $v) => $q->where('activity_id', $v))
            ->when($f['module'] ?? null, fn ($q, $v) => $q->whereHas('activity', fn ($q) => $q->where('module', $v)))
            ->when($f['due_from'] ?? null, fn ($q, $v) => $q->whereDate('due_on', '>=', $v))
            ->when($f['due_to'] ?? null, fn ($q, $v) => $q->whereDate('due_on', '<=', $v))
            ->when($bool('overdue'), fn ($q) => $q->whereIn('status', TaskStatus::OPEN)->whereDate('due_on', '<', now()->toDateString()))
            ->when($bool('mine'), fn ($q) => $q->where('worker_id', $this->access->currentWorker()?->id))
            ->orderBy('due_on')->orderBy('code')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50)));
    }

    public function show(string $farm, Task $task): TaskResource
    {
        return new TaskResource($this->access->visibleTask($task)->load([...self::WITH, 'logs.recorder', 'photos']));
    }

    /** start, pause, resume, submit, note: the event comes from the route. */
    public function step(Request $request, string $farm, Task $task): TaskResource
    {
        $event = TaskEvent::from($request->route('step'));
        $ctx = $request->validate([
            'occurred_at' => ['sometimes', 'date'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'accuracy_m' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'quantity' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20', 'exists:units,code'],
            'note' => [$event === TaskEvent::Note ? 'required_without:quantity' : 'sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $ctx['device_id'] = $request->attributes->get('device_id');

        return new TaskResource($this->flow->workerStep($task, $event, $ctx)->load(self::WITH));
    }

    public function verify(Request $request, string $farm, Task $task): TaskResource
    {
        $data = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return new TaskResource($this->flow->verify($task, $data['note'] ?? null)->load(self::WITH));
    }

    public function reject(Request $request, string $farm, Task $task): TaskResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return new TaskResource($this->flow->reject($task, $data['reason'])->load(self::WITH));
    }

    public function cancel(Request $request, string $farm, Task $task): TaskResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return new TaskResource($this->flow->cancel($task, $data['reason'])->load(self::WITH));
    }

    public function photo(Request $request, string $farm, Task $task): JsonResponse
    {
        $data = $request->validate([
            'media_id' => ['required', 'uuid'],
            'taken_at' => ['sometimes', 'nullable', 'date'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'accuracy_m' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $photo = $this->evidence->attachPhoto($task, $data);

        return response()->json(['data' => [
            'id' => $photo->id, 'task_id' => $photo->task_id, 'media_id' => $photo->media_id,
            'taken_at' => $photo->taken_at?->toIso8601ZuluString('millisecond'), 'caption' => $photo->caption,
        ]], $photo->wasRecentlyCreated ? 201 : 200);
    }
}
