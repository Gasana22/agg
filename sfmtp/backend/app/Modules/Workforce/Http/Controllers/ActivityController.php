<?php

namespace App\Modules\Workforce\Http\Controllers;

use App\Modules\Workforce\Application\Activities;
use App\Modules\Workforce\Application\TaskFlow;
use App\Modules\Workforce\Application\WorkforceAccess;
use App\Modules\Workforce\Domain\Enums\ActivityStatus;
use App\Modules\Workforce\Domain\Enums\Priority;
use App\Modules\Workforce\Domain\Enums\SubjectType;
use App\Modules\Workforce\Domain\Models\Activity;
use App\Modules\Workforce\Http\Resources\ActivityResource;
use App\Support\Http\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ActivityController
{
    private const WITH = ['type', 'plot', 'location', 'creator'];

    public function __construct(
        private readonly Activities $activities,
        private readonly TaskFlow $flow,
        private readonly WorkforceAccess $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', Rule::in(ActivityStatus::values())],
            'filter.module' => ['sometimes', 'string', 'max:20'],
            'filter.subject_type' => ['sometimes', Rule::in(SubjectType::values())],
            'filter.subject_id' => ['sometimes', 'uuid'],
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $f = $data['filter'] ?? [];

        return ActivityResource::collection($this->access->activities()->with(self::WITH)
            ->withCount([
                'tasks as tasks_total',
                'tasks as tasks_open' => fn ($q) => $q->whereIn('status', ['assigned', 'in_progress', 'paused', 'rejected']),
                'tasks as tasks_submitted' => fn ($q) => $q->where('status', 'submitted'),
                'tasks as tasks_verified' => fn ($q) => $q->where('status', 'verified'),
            ])
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['module'] ?? null, fn ($q, $v) => $q->where('module', $v))
            ->when($f['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($f['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('planned_on', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('planned_on', '<=', $v))
            ->orderByDesc('planned_on')->orderByDesc('created_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50)));
    }

    public function show(string $farm, Activity $activity): ActivityResource
    {
        return new ActivityResource($this->access->visibleActivity($activity)->load([...self::WITH, 'tasks.worker', 'tasks.verifier']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'activity_type_id' => ['required', 'uuid'],
            'title' => ['sometimes', 'nullable', 'string', 'min:2', 'max:150'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'subject_type' => ['required_with:subject_id', Rule::in(SubjectType::values())],
            'subject_id' => [Rule::requiredIf(fn () => $request->input('subject_type', 'general') !== 'general'), 'nullable', 'uuid'],
            'planned_on' => ['sometimes', 'date'],
            'due_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:planned_on'],
            'priority' => ['sometimes', Rule::in(Priority::values())],
            'target_quantity' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999'],
            'target_unit' => ['sometimes', 'nullable', 'string', 'max:20', 'exists:units,code'],
            'worker_ids' => ['required', 'array', 'min:1', 'max:50'],
            'worker_ids.*' => ['uuid'],
        ]);
        $data['subject_type'] ??= 'general';

        return (new ActivityResource($this->activities->create($data)->load([...self::WITH, 'tasks.worker'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $farm, Activity $activity): ActivityResource
    {
        $this->access->visibleActivity($activity);
        OptimisticLock::check($request, $activity);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:2', 'max:150'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'due_on' => ['sometimes', 'nullable', 'date'],
            'priority' => ['sometimes', Rule::in(Priority::values())],
            'target_quantity' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999'],
            'target_unit' => ['sometimes', 'nullable', 'string', 'max:20', 'exists:units,code'],
        ]);

        return new ActivityResource($this->activities->update($activity, $data)->load([...self::WITH, 'tasks.worker']));
    }

    public function assign(Request $request, string $farm, Activity $activity): ActivityResource
    {
        $this->access->visibleActivity($activity);
        $data = $request->validate(['worker_ids' => ['required', 'array', 'min:1', 'max:50'], 'worker_ids.*' => ['uuid']]);

        return new ActivityResource($this->activities->assign($activity, $data['worker_ids'])->load([...self::WITH, 'tasks.worker']));
    }

    public function cancel(Request $request, string $farm, Activity $activity): ActivityResource
    {
        $this->access->visibleActivity($activity);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return new ActivityResource($this->activities->cancel($activity, $data['reason'], $this->flow)->load([...self::WITH, 'tasks.worker']));
    }
}
