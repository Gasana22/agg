<?php

namespace App\Modules\Workforce\Http\Controllers;

use App\Modules\Workforce\Application\LeaveDesk;
use App\Modules\Workforce\Application\WorkforceAccess;
use App\Modules\Workforce\Domain\Enums\LeaveKind;
use App\Modules\Workforce\Domain\Enums\LeaveStatus;
use App\Modules\Workforce\Domain\Models\Leave;
use App\Modules\Workforce\Http\Resources\LeaveResource;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class LeaveController
{
    private const WITH = ['worker', 'requester', 'decider'];

    public function __construct(private readonly LeaveDesk $desk, private readonly WorkforceAccess $access) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', Rule::in(LeaveStatus::values())],
            'filter.worker_id' => ['sometimes', 'uuid'],
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date'],
        ]);
        $f = $data['filter'] ?? [];

        return LeaveResource::collection($this->access->leave()->with(self::WITH)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['worker_id'] ?? null, fn ($q, $v) => $q->where('worker_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('to_on', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('from_on', '<=', $v))
            ->orderByDesc('from_on')->orderBy('id')->limit(500)->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['sometimes', 'uuid'],
            'worker_id' => ['sometimes', 'uuid'],
            'kind' => ['required', Rule::in(LeaveKind::values())],
            'from_on' => ['required', 'date'],
            'to_on' => ['required', 'date', 'after_or_equal:from_on'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return (new LeaveResource($this->desk->request($data)->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function approve(Request $request, string $farm, Leave $leave): LeaveResource
    {
        $note = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']])['note'] ?? null;

        return new LeaveResource($this->desk->decide($leave, true, $note)->load(self::WITH));
    }

    public function reject(Request $request, string $farm, Leave $leave): LeaveResource
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);

        return new LeaveResource($this->desk->decide($leave, false, $data['note'])->load(self::WITH));
    }

    public function cancel(string $farm, Leave $leave): LeaveResource
    {
        if (! $this->access->leave()->whereKey($leave->id)->exists()) {
            throw ApiException::notFound();
        }

        return new LeaveResource($this->desk->cancel($leave)->load(self::WITH));
    }
}
