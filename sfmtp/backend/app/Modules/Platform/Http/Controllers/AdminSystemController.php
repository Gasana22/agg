<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Platform\Application\SystemHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminSystemController
{
    public function health(SystemHealth $health): JsonResponse
    {
        return new JsonResponse(['data' => [
            'checks' => $health->checks(),
            'versions' => $health->versions(),
            'checked_at' => now()->toIso8601ZuluString(),
        ]]);
    }

    /**
     * Platform audit trail: actions by platform staff and platform-level
     * events (sign-ins, billing). Farm operational entries stay with the farm.
     */
    public function auditLogs(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.action' => ['sometimes', 'string', 'max:100'],
            'filter.user_id' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $data['filter'] ?? [];

        $page = AuditLog::query()
            ->where(fn ($q) => $q->whereNull('farm_id')->orWhere('action', 'like', 'admin.%')->orWhere('action', 'like', 'support.%'))
            ->when($filter['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', $v.'%'))
            ->when($filter['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50));

        return JsonResource::collection($page->through(fn (AuditLog $log) => [
            'id' => $log->id,
            'type' => 'audit_log',
            'action' => $log->action,
            'farm_id' => $log->farm_id,
            'user_id' => $log->user_id,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'ip' => $log->ip,
            'request_id' => $log->request_id,
            'created_at' => $log->created_at->toIso8601ZuluString('microsecond'),
        ]));
    }

    public function failedJobs(): JsonResponse
    {
        $jobs = DB::table('failed_jobs')->orderByDesc('failed_at')->limit(100)->get()->map(fn ($j) => [
            'id' => $j->uuid,
            'queue' => $j->queue,
            'job' => json_decode($j->payload, true)['displayName'] ?? null,
            'error' => Str::limit(strtok($j->exception, "\n") ?: '', 300),
            'failed_at' => Carbon::parse($j->failed_at)->toIso8601ZuluString(),
        ]);

        return new JsonResponse(['data' => $jobs]);
    }

    public function backups(): JsonResponse
    {
        $runs = DB::table('backup_runs')->orderByDesc('finished_at')->limit(60)->get()->map(fn ($b) => [
            'id' => $b->id,
            'status' => $b->status,
            'started_at' => $b->started_at ? Carbon::parse($b->started_at)->toIso8601ZuluString() : null,
            'finished_at' => Carbon::parse($b->finished_at)->toIso8601ZuluString(),
            'size_bytes' => $b->size_bytes !== null ? (int) $b->size_bytes : null,
            'location' => $b->location,
            'checksum' => $b->checksum,
            'message' => $b->message,
        ]);

        return new JsonResponse(['data' => $runs]);
    }
}
