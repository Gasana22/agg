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
        private readonly CropMetrics $crops,
        private readonly FarmPermissions $permissions,
        private readonly TenantContext $context,
    ) {}

    /** @return array{kpis:array<int,string>, widgets:array<int,string>, quick_actions:array<int,string>} */
    private function layout(string $dashboard): array
    {
        $trace = ['trace.open_batches', 'trace.events'];

        return match ($dashboard) {
            'owner' => ['kpis' => ['farm.area', 'structure.mapped_area', 'crop.active_cycles', 'crop.actual_yield', 'farm.members', ...$trace], 'widgets' => ['setup_checklist', 'pest_disease_alerts', 'upcoming_harvests', 'recent_trace_events', 'trace_activity'], 'quick_actions' => ['invite_member', 'view_map', 'new_batch', 'view_audit_log']],
            'manager' => ['kpis' => ['crop.active_cycles', 'crop.incidents_open', 'structure.plots', 'farm.members', ...$trace], 'widgets' => ['operations_to_verify', 'pest_disease_alerts', 'recent_trace_events', 'trace_activity'], 'quick_actions' => ['invite_member', 'start_cycle', 'view_map', 'new_batch']],
            'agronomist' => [
                'kpis' => ['crop.active_cycles', 'crop.planted_area', 'crop.near_harvest', 'crop.expected_yield', 'crop.actual_yield', 'crop.yield_per_ha', 'crop.incidents_open', 'crop.treatments_active'],
                'widgets' => ['pest_disease_alerts', 'operations_to_verify', 'upcoming_harvests', 'expected_vs_actual_yield', 'recent_trace_events'],
                'quick_actions' => ['start_cycle', 'record_operation', 'report_observation', 'record_harvest', 'new_crop_plan', 'view_map'],
            ],
            'livestock', 'store' => ['kpis' => $trace, 'widgets' => ['recent_trace_events'], 'quick_actions' => ['view_map', 'new_batch']],
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
            'structure.plots' => ['label' => 'Plots', 'format' => 'number', 'permission' => 'structure.view',
                'value' => fn () => $this->metrics->plots()],
            'structure.mapped_area' => ['label' => 'Mapped area', 'format' => 'quantity', 'permission' => 'structure.view',
                'value' => fn () => ['value' => number_format($this->metrics->mappedAreaHa(), 2, '.', ''), 'unit' => 'ha']],
            'crop.active_cycles' => ['label' => 'Active crop cycles', 'format' => 'number', 'permission' => 'crops.plans.view',
                'value' => fn () => $this->crops->activeCycles()],
            'crop.planted_area' => ['label' => 'Planted area', 'format' => 'quantity', 'permission' => 'crops.plans.view',
                'value' => fn () => ['value' => number_format($this->crops->plantedAreaHa(), 2, '.', ''), 'unit' => 'ha']],
            'crop.near_harvest' => ['label' => 'Near harvest (14 days)', 'format' => 'number', 'permission' => 'crops.plans.view',
                'value' => fn () => $this->crops->nearHarvest()],
            'crop.expected_yield' => ['label' => 'Expected yield (open cycles)', 'format' => 'quantity', 'permission' => 'crops.plans.view',
                'value' => fn () => ['value' => number_format($this->crops->expectedYieldKg(), 1, '.', ''), 'unit' => 'kg']],
            'crop.actual_yield' => ['label' => 'Harvested', 'format' => 'quantity', 'permission' => 'crops.harvest.view',
                'value' => fn (Period $p) => ['value' => number_format($this->crops->harvestedKg($p), 1, '.', ''), 'unit' => 'kg'],
                'previous' => fn (Period $p) => ['value' => number_format($this->crops->harvestedKg($p->previous()), 1, '.', ''), 'unit' => 'kg']],
            'crop.yield_per_ha' => ['label' => 'Yield per hectare', 'format' => 'quantity', 'permission' => 'crops.harvest.view',
                'value' => fn (Period $p) => ($v = $this->crops->yieldPerHa($p)) === null ? null : ['value' => number_format($v, 1, '.', ''), 'unit' => 'kg/ha']],
            'crop.incidents_open' => ['label' => 'Open pest & disease incidents', 'format' => 'number', 'permission' => 'crops.operations.view',
                'value' => fn () => $this->crops->openIncidents()],
            'crop.treatments_active' => ['label' => 'Cycles under withholding', 'format' => 'number', 'permission' => 'crops.operations.view',
                'value' => fn () => $this->crops->cyclesUnderWithholding()],
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
                    ['key' => 'structure', 'label' => 'Map your blocks and plots', 'done' => $this->metrics->plots() > 0, 'href' => "/farms/{$farm->id}/structure"],
                    ['key' => 'crops', 'label' => 'Start your first crop cycle', 'done' => $this->crops->anyCycle(), 'href' => "/farms/{$farm->id}/crops"],
                    ['key' => 'members', 'label' => 'Invite your team', 'done' => $this->metrics->membersActive() > 1, 'href' => "/farms/{$farm->id}/members"],
                    ['key' => 'approval', 'label' => 'Farm approved by SFMTP', 'done' => $farm->status->value === 'active', 'href' => null],
                ]]],
            'pest_disease_alerts' => ['type' => 'action_list', 'permission' => 'crops.operations.view', 'inline' => true,
                'data' => fn () => ['items' => $this->crops->incidentAlerts()]],
            'operations_to_verify' => ['type' => 'action_list', 'permission' => 'crops.operations.approve', 'inline' => true,
                'data' => fn () => ['items' => $this->crops->operationsToVerify()]],
            'upcoming_harvests' => ['type' => 'action_list', 'permission' => 'crops.plans.view', 'inline' => true,
                'data' => fn () => ['items' => $this->crops->upcomingHarvests()]],
            'expected_vs_actual_yield' => ['type' => 'chart', 'permission' => 'crops.harvest.view', 'inline' => false,
                'data' => function (Period $p) {
                    $d = $this->crops->expectedVsActual($p);

                    return [
                        'chart' => 'bar',
                        'x' => ['type' => 'category', 'values' => $d['labels']],
                        'series' => [
                            ['key' => 'expected', 'label' => 'Expected', 'unit' => 'kg', 'values' => $d['expected']],
                            ['key' => 'actual', 'label' => 'Harvested', 'unit' => 'kg', 'values' => $d['actual']],
                        ],
                    ];
                }],
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
            'invite_member' => ['label' => 'Invite member', 'permission' => 'members.invite_workers', 'target' => "/farms/{$id}/members?invite=1"],
            'view_map' => ['label' => 'Farm map', 'permission' => 'structure.view', 'target' => "/farms/{$id}/structure"],
            'start_cycle' => ['label' => 'Start crop cycle', 'permission' => 'crops.plans.manage', 'target' => "/farms/{$id}/crops?new=cycle"],
            'record_operation' => ['label' => 'Record field work', 'permission' => 'crops.operations.record', 'target' => "/farms/{$id}/crops?action=operation"],
            'report_observation' => ['label' => 'Report pest / disease', 'permission' => 'crops.operations.record', 'target' => "/farms/{$id}/crops?action=observation"],
            'record_harvest' => ['label' => 'Record harvest', 'permission' => 'crops.harvest.record', 'target' => "/farms/{$id}/crops?action=harvest"],
            'new_crop_plan' => ['label' => 'New crop plan', 'permission' => 'crops.plans.manage', 'target' => "/farms/{$id}/crops?tab=plans&new=plan"],
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
        // Quantities compare on their value.
        [$current, $previous] = [is_array($current) ? ($current['value'] ?? null) : $current, is_array($previous) ? ($previous['value'] ?? null) : $previous];
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
