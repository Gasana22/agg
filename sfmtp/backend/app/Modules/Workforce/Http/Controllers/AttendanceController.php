<?php

namespace App\Modules\Workforce\Http\Controllers;

use App\Modules\Tenancy\TenantContext;
use App\Modules\Workforce\Application\AttendanceBook;
use App\Modules\Workforce\Application\FieldEvidence;
use App\Modules\Workforce\Application\WorkforceAccess;
use App\Modules\Workforce\Domain\Enums\AttendanceSource;
use App\Modules\Workforce\Domain\Models\Attendance;
use App\Modules\Workforce\Domain\Models\GpsPoint;
use App\Modules\Workforce\Domain\Models\Worker;
use App\Modules\Workforce\Http\Resources\AttendanceResource;
use App\Support\Http\ApiException;
use App\Support\Http\OptimisticLock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AttendanceController
{
    public function __construct(
        private readonly AttendanceBook $book,
        private readonly FieldEvidence $evidence,
        private readonly WorkforceAccess $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date'],
            'filter.worker_id' => ['sometimes', 'uuid'],
            'filter.open' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);
        $f = $data['filter'] ?? [];

        return AttendanceResource::collection($this->access->attendance()->with('worker')
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('work_date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('work_date', '<=', $v))
            ->when($f['worker_id'] ?? null, fn ($q, $v) => $q->where('worker_id', $v))
            ->when(isset($f['open']) && filter_var($f['open'], FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->whereNull('check_out_at'))
            ->orderByDesc('work_date')->orderByDesc('check_in_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 100)));
    }

    public function checkIn(Request $request): JsonResponse
    {
        $attendance = $this->book->checkIn($this->pointRules($request), AttendanceSource::Web);

        return (new AttendanceResource($attendance->load('worker')))->response()->setStatusCode($attendance->wasRecentlyCreated ? 201 : 200);
    }

    public function checkOut(Request $request): AttendanceResource
    {
        return new AttendanceResource($this->book->checkOut($this->pointRules($request))->load('worker'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_id' => ['required', 'uuid'],
            'check_in_at' => ['required', 'date'],
            'check_out_at' => ['sometimes', 'nullable', 'date'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $worker = Worker::find($data['worker_id']) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['worker_id' => ['The selected worker does not exist in this farm.']]);

        return (new AttendanceResource($this->book->enter($worker, $data)->load('worker')))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $farm, Attendance $attendance): AttendanceResource
    {
        OptimisticLock::check($request, $attendance);
        $data = $request->validate([
            'check_in_at' => ['sometimes', 'date'],
            'check_out_at' => ['sometimes', 'nullable', 'date'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return new AttendanceResource($this->book->correct($attendance, $data)->load('worker'));
    }

    /** GPS points from the worker's phone (normally through sync). */
    public function track(Request $request): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'array', 'min:1', 'max:500'],
            'points.*.id' => ['sometimes', 'uuid'],
            'points.*.recorded_at' => ['required', 'date'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'points.*.accuracy_m' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'points.*.task_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        return response()->json(['data' => $this->evidence->recordTrack($data['points'], $request->attributes->get('device_id'))]);
    }

    /** A worker's track for one day, for supervisors holding worker.gps.view. */
    public function workerTrack(Request $request, string $farm, Worker $worker): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date']]);
        $tz = app(TenantContext::class)->farm()->timezone;
        $from = CarbonImmutable::parse($data['date'], $tz)->startOfDay()->utc();

        $points = GpsPoint::where('worker_id', $worker->id)->whereBetween('recorded_at', [$from, $from->addDay()])
            ->orderBy('recorded_at')->limit(5000)->get(['recorded_at', 'lat', 'lng', 'accuracy_m', 'task_id']);

        return response()->json(['data' => $points->map(fn ($p) => [
            'recorded_at' => $p->recorded_at->toIso8601ZuluString('millisecond'),
            'lat' => $p->lat, 'lng' => $p->lng, 'accuracy_m' => $p->accuracy_m, 'task_id' => $p->task_id,
        ])->values()]);
    }

    private function pointRules(Request $request): array
    {
        return $request->validate([
            'id' => ['sometimes', 'uuid'],
            'occurred_at' => ['sometimes', 'date'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'accuracy_m' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'photo_id' => ['sometimes', 'nullable', 'uuid'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
    }
}
