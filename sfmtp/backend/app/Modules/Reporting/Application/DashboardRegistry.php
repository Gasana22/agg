<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use Closure;

/**
 * Server-driven dashboards (docs/05, docs/06 §3). Each dashboard lists its
 * KPIs, widgets and quick actions; every entry declares the permission it
 * needs and is left out when the member lacks it. Later phases register more
 * entries here as their modules land.
 */
class DashboardRegistry
{
    public const FARM_DASHBOARDS = ['owner', 'manager', 'agronomist', 'livestock', 'store', 'accountant', 'worker'];

    public function __construct(
        private readonly FarmMetrics $metrics,
        private readonly FarmPermissions $permissions,
        private readonly TenantContext $context,
    ) {}

    /** @return array{kpis:array<int,string>, widgets:array<int,string>, quick_actions:array<int,string>} */
    private function layout(string $dashboard): array
    {
        $trace = ['trace.open_batches', 'trace.events'];

        return match ($dashboard) {
            'owner' => ['kpis' => ['farm.area', 'farm.members', ...$trace], 'widgets' => ['setup_checklist', 'recent_trace_events', 'trace_activity'], 'quick_actions' => ['new_batch', 'view_audit_log']],
            'manager' => ['kpis' => ['farm.members', ...$trace], 'widgets' => ['recent_trace_events', 'trace_activity'], 'quick_actions' => ['new_batch']],
            'agronomist', 'livestock', 'store' => ['kpis' => $trace, 'widgets' => ['recent_trace_events'], 'quick_actions' => ['new_batch']],
            'accountant' => ['kpis' => ['trace.open_batches'], 'widgets' => ['recent_trace_events'], 'quick_actions' => ['view_audit_log']],
            'worker' => ['kpis' => [], 'widgets' => [], 'quick_actions' => []],
        };
    }

    /**
     * @return array<string, array{label:string, format:string, permission:?string, value:Closure(Period):mixed, previous?:Closure(Period):mixed}>
     */
    private function kpis(): array
    {
        $farm = $this->context->farm();

        return [
            'farm.area' => ['label' => 'Farm area', 'format' => 'quantity', 'permission' => 'farm.profile.view',
                'value' => fn () => $farm->size_ha === null ? null : ['value' => $farm->size_ha, 'unit' => 'ha']],
            'farm.members' => ['label' => 'Active members', 'format' => 'number', 'permission' => 'members.view',
                'value' => fn () => $this->metrics->membersActive()],
            'trace.open_batches' => ['label' => 'Open batches', 'format' => 'number', 'permission' => 'trace.batches.view',
                'value' => fn () => $this->metrics->openBatches()],
            'trace.events' => ['label' => 'Traceability events', 'format' => 'number', 'permission' => 'trace.batches.view',
                'value' => fn (Period $p) => $this->metrics->traceEvents($p),
                'previous' => fn (Period $p) => $this->metrics->traceEvents($p->previous())],
        ];
    }

    /**
     * @return array<string, array{type:string, permission:?string, inline:bool, data:Closure(Period):array}>
     */
    private function widgets(): array
    {
        $farm = $this->context->farm();

        return [
            'setup_checklist' => ['type' => 'checklist', 'permission' => 'farm.profile.manage', 'inline' => true,
                'data' => fn () => ['items' => [
                    ['key' => 'profile', 'label' => 'Complete the farm profile', 'done' => $farm->district !== null && $farm->size_ha !== null, 'href' => "/farms/{$farm->id}/settings"],
                    ['key' => 'members', 'label' => 'Invite your team', 'done' => $this->metrics->membersActive() > 1, 'href' => "/farms/{$farm->id}/members"],
                    ['key' => 'approval', 'label' => 'Farm approved by SFMTP', 'done' => $farm->status->value === 'active', 'href' => null],
                ]]],
            'recent_trace_events' => ['type' => 'action_list', 'permission' => 'trace.batches.view', 'inline' => true,
                'data' => fn () => ['items' => $this->metrics->recentTraceEvents()]],
            'trace_activity' => ['type' => 'chart', 'permission' => 'trace.batches.view', 'inline' => false,
                'data' => function (Period $p) use ($farm) {
                    $series = $this->metrics->traceEventsPerDay($p, $farm->timezone);

                    return [
                        'chart' => 'bar',
                        'x' => ['type' => 'date', 'values' => array_column($series, 'date')],
                        'series' => [['key' => 'events', 'label' => 'Events', 'unit' => 'events', 'values' => array_column($series, 'count')]],
                    ];
                }],
        ];
    }

    /** @return array<string, array{label:string, permission:string, target:string}> */
    private function quickActions(): array
    {
        $id = $this->context->farmId();

        return [
            // 'invite_member' arrives with member invitations (Phase 3).
            'new_batch' => ['label' => 'New batch', 'permission' => 'trace.batches.create', 'target' => "/farms/{$id}/traceability/batches/new"],
            'view_audit_log' => ['label' => 'Audit log', 'permission' => 'audit.view', 'target' => "/farms/{$id}/audit-log"],
        ];
    }

    public function summary(string $dashboard, Period $period): array
    {
        $this->authorize($dashboard);
        $layout = $this->layout($dashboard);
        $farm = $this->context->farm();
        $kpiDefs = $this->kpis();
        $widgetDefs = $this->widgets();
        $actionDefs = $this->quickActions();

        $kpis = [];
        foreach ($layout['kpis'] as $key) {
            $def = $kpiDefs[$key];
            if (! $this->can($def['permission'])) {
                continue;
            }
            $value = ($def['value'])($period);
            $kpis[] = [
                'key' => $key,
                'label' => $def['label'],
                'value' => $value,
                'format' => $def['format'],
                'delta' => isset($def['previous']) ? $this->delta($value, ($def['previous'])($period)) : null,
            ];
        }

        $widgets = [];
        foreach ($layout['widgets'] as $key) {
            $def = $widgetDefs[$key];
            if (! $this->can($def['permission'])) {
                continue;
            }
            $widgets[] = $def['inline']
                ? ['key' => $key, 'type' => $def['type'], 'inline' => true, 'data' => ($def['data'])($period)]
                : ['key' => $key, 'type' => $def['type'], 'inline' => false,
                    'href' => "/api/v1/farms/{$farm->id}/dashboards/{$dashboard}/widgets/{$key}?period={$period->key}"];
        }

        $actions = [];
        foreach ($layout['quick_actions'] as $key) {
            $def = $actionDefs[$key];
            if ($this->can($def['permission'])) {
                $actions[] = ['key' => $key, 'label' => $def['label'], 'target' => $def['target']];
            }
        }

        return [
            'dashboard' => $dashboard,
            'farm' => ['id' => $farm->id, 'name' => $farm->name, 'currency' => $farm->currency, 'timezone' => $farm->timezone],
            'period' => $period->toArray($farm->timezone),
            'generated_at' => now()->toIso8601ZuluString(),
            'kpis' => $kpis,
            'widgets' => $widgets,
            'quick_actions' => $actions,
            'alerts' => [],
        ];
    }

    public function widget(string $dashboard, string $widget, Period $period): array
    {
        $this->authorize($dashboard);
        $def = $this->widgets()[$widget] ?? null;

        if ($def === null || ! in_array($widget, $this->layout($dashboard)['widgets'], true)) {
            throw ApiException::notFound();
        }
        if (! $this->can($def['permission'])) {
            throw ApiException::forbidden();
        }

        return ['key' => $widget, 'type' => $def['type']] + ($def['data'])($period);
    }

    /** Cache key part: two members see the same data only with the same permissions. */
    public function permissionFingerprint(): string
    {
        $keys = array_keys($this->permissions->current());
        sort($keys);

        return substr(hash('sha256', implode(',', $keys)), 0, 16);
    }

    private function authorize(string $dashboard): void
    {
        if (! in_array($dashboard, self::FARM_DASHBOARDS, true)) {
            throw ApiException::notFound();
        }
        if (! $this->can("dashboard.{$dashboard}.view")) {
            throw ApiException::forbidden('dashboard_not_available', 'Your role does not include this dashboard.');
        }
    }

    private function can(?string $permission): bool
    {
        return $permission === null || $this->permissions->allows($permission);
    }

    private function delta(mixed $current, mixed $previous): ?array
    {
        if (! is_numeric($current) || ! is_numeric($previous)) {
            return null;
        }
        if ((float) $previous === 0.0) {
            return ['value' => null, 'direction' => $current > 0 ? 'up' : 'flat', 'vs' => 'previous_period'];
        }
        $change = ($current - $previous) / $previous;

        return [
            'value' => round($change, 4),
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
            'vs' => 'previous_period',
        ];
    }
}
