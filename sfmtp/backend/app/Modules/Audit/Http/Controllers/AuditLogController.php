<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogController
{
    public function index(Request $request, TenantContext $context): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'filter.action' => ['sometimes', 'string', 'max:100'],
            'filter.entity_type' => ['sometimes', 'string', 'max:100'],
            'filter.entity_id' => ['sometimes', 'string', 'max:64'],
            'filter.user_id' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])['filter'] ?? [];

        $logs = AuditLog::query()
            ->where('farm_id', $context->farmId())
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['entity_type'] ?? null, fn ($q, $v) => $q->where('entity_type', $v))
            ->when($filters['entity_id'] ?? null, fn ($q, $v) => $q->where('entity_id', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->query('per_page', 25));

        return JsonResource::collection($logs->through(fn (AuditLog $log) => [
            'id' => $log->id,
            'type' => 'audit_log',
            'action' => $log->action,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'user_id' => $log->user_id,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'ip' => $log->ip,
            'request_id' => $log->request_id,
            'created_at' => $log->created_at->toIso8601ZuluString('microsecond'),
        ]));
    }
}
