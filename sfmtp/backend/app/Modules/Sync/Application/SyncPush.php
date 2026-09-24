<?php

namespace App\Modules\Sync\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Workforce\Application\AttendanceBook;
use App\Modules\Workforce\Application\FieldEvidence;
use App\Modules\Workforce\Application\LeaveDesk;
use App\Modules\Workforce\Application\MediaNotReady;
use App\Modules\Workforce\Application\TaskFlow;
use App\Modules\Workforce\Application\WorkforceAccess;
use App\Modules\Workforce\Domain\Enums\LeaveKind;
use App\Modules\Workforce\Domain\Enums\TaskEvent;
use App\Modules\Workforce\Domain\Models\Attendance;
use App\Modules\Workforce\Domain\Models\Task;
use App\Modules\Workforce\Http\Controllers\TaskController;
use App\Modules\Workforce\Http\Resources\AttendanceResource;
use App\Modules\Workforce\Http\Resources\TaskResource;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * POST /sync/push (docs/08 §3–4). Mutations are applied in the order the
 * phone made them, each in its own transaction, through the same services
 * and permission checks as the online API.
 *
 * Results:
 * - applied: done (the result is stored, so a retry returns `duplicate`);
 * - conflict: a state change that no longer fits the server state, e.g.
 *   completing a task that was cancelled meanwhile. The step is kept in the
 *   task log as evidence and the server's record is returned;
 * - rejected: not allowed or invalid; the phone shows the error;
 * - deferred: a photo the record needs has not been uploaded yet; retry later;
 * - error: an unexpected failure; nothing was stored, retry later.
 */
class SyncPush
{
    /** entity => op => [permission, handler] */
    private array $handlers;

    public function __construct(
        private readonly FarmPermissions $permissions,
        private readonly WorkforceAccess $access,
        private readonly TaskFlow $flow,
        private readonly FieldEvidence $evidence,
        private readonly AttendanceBook $attendance,
        private readonly LeaveDesk $leave,
        private readonly TenantContext $context,
    ) {
        $this->handlers = [
            'worker_task_logs' => ['insert' => ['tasks.execute', $this->taskStep(...)]],
            'worker_task_photos' => ['insert' => ['tasks.execute', $this->taskPhoto(...)]],
            'worker_attendance' => [
                'check_in' => ['attendance.record', $this->checkIn(...)],
                'check_out' => ['attendance.record', $this->checkOut(...)],
            ],
            'worker_gps_points' => ['insert' => ['attendance.record', $this->gps(...)]],
            'worker_leave' => ['insert' => ['leave.request', $this->leaveRequest(...)]],
        ];
    }

    /**
     * @param  array<int, array<string,mixed>>  $mutations
     * @return array<int, array<string,mixed>>
     */
    public function push(array $mutations, ?string $deviceId, Request $request): array
    {
        $results = [];
        foreach ($mutations as $m) {
            $results[] = $this->apply($m, $deviceId, $request);
        }

        return $results;
    }

    private function apply(array $m, ?string $deviceId, Request $request): array
    {
        $base = ['mutation_id' => $m['mutation_id'], 'entity' => $m['entity'], 'op' => $m['op'], 'id' => $m['id'] ?? null];

        $stored = DB::table('sync_mutations')->where('user_id', Auth::id())->where('mutation_id', $m['mutation_id'])
            ->where('farm_id', $this->context->farmId())->first();
        if ($stored) {
            return ['status' => 'duplicate', 'original_status' => $stored->status] + json_decode($stored->result, true) + $base;
        }

        [$permission, $handler] = $this->handlers[$m['entity']][$m['op']] ?? [null, null];
        if (! $handler) {
            return $this->store($m, $deviceId, $base + ['status' => 'rejected', 'error' => ['code' => 'unsupported', 'message' => "Unsupported mutation {$m['entity']}.{$m['op']}."]]);
        }

        try {
            return DB::transaction(function () use ($m, $deviceId, $base, $permission, $handler, $request) {
                if (! $this->permissions->allows($permission)) {
                    throw ApiException::forbidden('forbidden', 'You do not have permission to do this in this farm.');
                }
                $data = (array) ($m['data'] ?? []);
                $ctx = array_merge($data, array_filter(['id' => $m['id'] ?? null, 'occurred_at' => $m['occurred_at'] ?? null, 'device_id' => $deviceId]));

                return $this->store($m, $deviceId, $base + $handler($ctx, $request));
            });
        } catch (MediaNotReady $e) {
            return $base + ['status' => 'deferred', 'error' => ['code' => 'media_missing', 'message' => $e->getMessage(), 'media_id' => $e->mediaId]];
        } catch (ValidationException $e) {
            return $this->store($m, $deviceId, $base + ['status' => 'rejected', 'error' => ['code' => 'validation_failed', 'message' => $e->getMessage(), 'errors' => $e->errors()]]);
        } catch (ApiException $e) {
            if ($e->status === 409 && ($conflict = $this->conflict($m, $e, $request))) {
                return $this->store($m, $deviceId, $base + $conflict);
            }

            return $this->store($m, $deviceId, $base + ['status' => 'rejected', 'error' => array_filter(['code' => $e->errorCode, 'message' => $e->getMessage(), 'errors' => $e->extra['errors'] ?? null])]);
        } catch (Throwable $e) {
            Log::error('sync mutation failed', ['mutation_id' => $m['mutation_id'], 'exception' => $e]);

            return $base + ['status' => 'error', 'error' => ['code' => 'server_error', 'message' => 'The server could not apply this change. It will be retried.']];
        }
    }

    /** A refused state change: keep the step as evidence and send back the server's record. */
    private function conflict(array $m, ApiException $e, Request $request): ?array
    {
        $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];

        if ($m['entity'] === 'worker_task_logs' && $e->errorCode === 'invalid_state_transition') {
            return DB::transaction(function () use ($m, $error, $request) {
                $task = Task::with(TaskController::WITH)->findOrFail($m['data']['task_id']);
                $this->flow->recordRefused($task, TaskEvent::from($m['data']['event']), array_merge((array) $m['data'], array_filter(['id' => $m['id'] ?? null, 'occurred_at' => $m['occurred_at'] ?? null])));

                return ['status' => 'conflict', 'error' => $error, 'server' => ['entity' => 'tasks', 'id' => $task->id, 'version' => $task->version, 'data' => (new TaskResource($task))->resolve($request)]];
            });
        }
        if ($m['entity'] === 'worker_attendance' && in_array($e->errorCode, ['already_checked_in', 'not_checked_in'], true)) {
            $record = isset($e->extra['attendance_id']) ? Attendance::find($e->extra['attendance_id']) : null;

            return ['status' => 'conflict', 'error' => $error, 'server' => $record
                ? ['entity' => 'attendance', 'id' => $record->id, 'version' => $record->version, 'data' => (new AttendanceResource($record))->resolve($request)] : null];
        }

        return null;
    }

    private function store(array $m, ?string $deviceId, array $result): array
    {
        if (in_array($result['status'], ['applied', 'conflict', 'rejected'], true)) {
            DB::table('sync_mutations')->insert([
                'id' => (string) Str::uuid7(),
                'farm_id' => $this->context->farmId(),
                'user_id' => Auth::id(),
                'mutation_id' => $m['mutation_id'],
                'device_id' => $deviceId,
                'entity' => $m['entity'],
                'op' => $m['op'],
                'record_id' => $result['id'] ?? null,
                'status' => $result['status'],
                'result' => json_encode(array_intersect_key($result, array_flip(['status', 'version', 'error', 'server']))),
                'occurred_at' => isset($m['occurred_at']) ? CarbonImmutable::parse($m['occurred_at'])->utc()->format('Y-m-d H:i:s.u') : null,
                'created_at' => now()->format('Y-m-d H:i:s.u'),
            ]);
        }

        return $result;
    }

    // Handlers: validate the mutation's data like the matching endpoint, then call the service.

    private function taskStep(array $ctx, Request $request): array
    {
        $this->validate($ctx, [
            'task_id' => ['required', 'uuid'],
            'event' => ['required', Rule::in(TaskEvent::WORKER)],
            'id' => ['required', 'uuid'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'unit' => ['nullable', 'string', 'max:20', 'exists:units,code'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $task = Task::find($ctx['task_id']) ?? throw ApiException::notFound();
        $task = $this->flow->workerStep($task, TaskEvent::from($ctx['event']), $ctx)->load(TaskController::WITH);

        return $this->applied($task->id, $task->version, ['entity' => 'tasks', 'data' => (new TaskResource($task))->resolve($request)]);
    }

    private function taskPhoto(array $ctx, Request $request): array
    {
        $this->validate($ctx, [
            'id' => ['required', 'uuid'],
            'task_id' => ['required', 'uuid'],
            'media_id' => ['required', 'uuid'],
            'taken_at' => ['nullable', 'date'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'caption' => ['nullable', 'string', 'max:200'],
        ]);
        $task = Task::find($ctx['task_id']) ?? throw ApiException::notFound();
        $photo = $this->evidence->attachPhoto($task, $ctx);

        return $this->applied($photo->id, null);
    }

    private function checkIn(array $ctx, Request $request): array
    {
        $this->validate($ctx, $this->pointRules() + ['id' => ['required', 'uuid']]);
        $record = $this->attendance->checkIn($ctx);

        return $this->applied($record->id, $record->version, ['entity' => 'attendance', 'data' => (new AttendanceResource($record))->resolve($request)]);
    }

    private function checkOut(array $ctx, Request $request): array
    {
        $this->validate($ctx, $this->pointRules() + ['attendance_id' => ['nullable', 'uuid']]);
        $record = $this->attendance->checkOut($ctx);

        return $this->applied($record->id, $record->version, ['entity' => 'attendance', 'data' => (new AttendanceResource($record))->resolve($request)]);
    }

    private function gps(array $ctx, Request $request): array
    {
        $this->validate($ctx, [
            'points' => ['required', 'array', 'min:1', 'max:500'],
            'points.*.id' => ['nullable', 'uuid'],
            'points.*.recorded_at' => ['required', 'date'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'points.*.accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'points.*.task_id' => ['nullable', 'uuid'],
        ]);

        return $this->applied(null, null, ['result' => $this->evidence->recordTrack($ctx['points'], $ctx['device_id'] ?? null)]);
    }

    private function leaveRequest(array $ctx, Request $request): array
    {
        $this->validate($ctx, [
            'id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(LeaveKind::values())],
            'from_on' => ['required', 'date'],
            'to_on' => ['required', 'date', 'after_or_equal:from_on'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        unset($ctx['worker_id']);
        $leave = $this->leave->request($ctx);

        return $this->applied($leave->id, $leave->version);
    }

    private function applied(?string $id, ?int $version, array $extra = []): array
    {
        return ['status' => 'applied', 'id' => $id, 'version' => $version] + ($extra ? ['server' => ['id' => $id, 'version' => $version] + $extra] : []);
    }

    private function pointRules(): array
    {
        return [
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'photo_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function validate(array $data, array $rules): void
    {
        Validator::make($data, $rules)->validate();
    }
}
