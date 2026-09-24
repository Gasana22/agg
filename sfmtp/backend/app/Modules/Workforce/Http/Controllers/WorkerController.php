<?php

namespace App\Modules\Workforce\Http\Controllers;

use App\Modules\Workforce\Application\Workers;
use App\Modules\Workforce\Application\WorkforceAccess;
use App\Modules\Workforce\Domain\Enums\EmploymentType;
use App\Modules\Workforce\Domain\Enums\WorkerStatus;
use App\Modules\Workforce\Domain\Models\Worker;
use App\Modules\Workforce\Http\Resources\WorkerResource;
use App\Support\Http\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class WorkerController
{
    public function __construct(private readonly Workers $workers, private readonly WorkforceAccess $access) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', Rule::in(WorkerStatus::values())],
            'filter.search' => ['sometimes', 'string', 'max:100'],
            'filter.linked' => ['sometimes', 'boolean'],
        ]);
        $f = $data['filter'] ?? [];

        return WorkerResource::collection($this->access->workers()->with('membership.user')
            ->when($f['status'] ?? 'active', fn ($q, $v) => $q->where('status', $v))
            ->when($f['search'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('full_name', 'like', "%{$v}%")->orWhere('worker_code', 'like', "%{$v}%")->orWhere('phone', 'like', "%{$v}%")))
            ->when(isset($f['linked']), fn ($q) => filter_var($f['linked'], FILTER_VALIDATE_BOOLEAN) ? $q->whereNotNull('farm_user_id') : $q->whereNull('farm_user_id'))
            ->orderBy('full_name')->get());
    }

    public function show(string $farm, Worker $worker): WorkerResource
    {
        return new WorkerResource($this->access->visibleWorker($worker)->load('membership.user'));
    }

    public function store(Request $request): JsonResponse
    {
        $worker = $this->workers->create($request->validate($this->rules(true)));

        return (new WorkerResource($worker->load('membership.user')))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $farm, Worker $worker): WorkerResource
    {
        OptimisticLock::check($request, $worker);

        return new WorkerResource($this->workers->update($worker, $request->validate($this->rules(false)))->load('membership.user'));
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return array_filter([
            'full_name' => [$required, 'string', 'min:2', 'max:150'],
            'farm_user_id' => ['sometimes', 'nullable', 'uuid'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9+() -]{6,30}$/'],
            'national_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:80'],
            'employment_type' => [$required, Rule::in(EmploymentType::values())],
            'daily_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'started_on' => ['sometimes', 'nullable', 'date'],
            'left_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:started_on'],
            'status' => $creating ? null : ['sometimes', Rule::in(WorkerStatus::values())],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }
}
