<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Support\Facades\Auth;

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
        private readonly LivestockMetrics $livestock,
        private readonly WorkforceMetrics $workforce,
        private readonly InventoryMetrics $inventory,
        private readonly FarmPermissions $permissions,
        private readonly TenantContext $context,
    ) {}

    /** @return array{kpis:array<int,string>, widgets:array<int,string>, quick_actions:array<int,string>} */
    private function layout(string $dashboard): array
    {
        $trace = ['trace.open_batches', 'trace.events'];

        return match ($dashboard) {
            'owner' => ['kpis' => ['farm.area', 'structure.mapped_area', 'crop.active_cycles', 'crop.actual_yield', 'livestock.head_count', 'livestock.milk', 'workers.present', 'tasks.pending', 'inventory.value', 'payables.open', 'farm.members', ...$trace], 'widgets' => ['setup_checklist', 'orders_to_approve', 'livestock_sale_requests', 'pest_disease_alerts', 'upcoming_harvests', 'withdrawal_alerts', 'recent_trace_events', 'trace_activity'], 'quick_actions' => ['invite_member', 'view_map', 'new_batch', 'view_audit_log']],
            'manager' => [
                'kpis' => ['tasks.today', 'tasks.completed', 'tasks.pending', 'tasks.overdue', 'workers.present', 'workers.absent', 'activities.active', 'crop.active_cycles', 'livestock.head_count', 'inventory.requests_pending', 'inventory.low'],
                'widgets' => ['verification_queue', 'schedule', 'overdue_tasks', 'leave_requests', 'pending_requests', 'purchase_requests_to_approve', 'worker_activity', 'operations_to_verify', 'pest_disease_alerts', 'vaccinations_due', 'recent_trace_events'],
                'quick_actions' => ['new_task', 'view_workers', 'invite_member', 'start_cycle', 'register_animal', 'view_map'],
            ],
            'agronomist' => [
                'kpis' => ['crop.active_cycles', 'crop.planted_area', 'crop.near_harvest', 'crop.expected_yield', 'crop.actual_yield', 'crop.yield_per_ha', 'crop.incidents_open', 'crop.treatments_active'],
                'widgets' => ['pest_disease_alerts', 'operations_to_verify', 'verification_queue', 'upcoming_harvests', 'expected_vs_actual_yield', 'recent_trace_events'],
                'quick_actions' => ['start_cycle', 'record_operation', 'report_observation', 'record_harvest', 'new_task', 'new_crop_plan', 'view_map'],
            ],
            'livestock' => [
                'kpis' => ['livestock.head_count', 'livestock.new_animals', 'livestock.pregnant', 'livestock.vaccinations_due', 'livestock.under_withdrawal', 'livestock.mortality_rate', 'livestock.milk', 'livestock.daily_gain', 'livestock.sold'],
                'widgets' => ['vaccinations_due', 'withdrawal_alerts', 'verification_queue', 'expected_births', 'weight_loss_alerts', 'milk_production', 'recent_trace_events'],
                'quick_actions' => ['register_animal', 'record_health', 'record_weight', 'record_production', 'new_task', 'request_sale', 'view_map'],
            ],
            'store' => [
                'kpis' => ['inventory.items', 'inventory.low', 'inventory.out', 'inventory.value', 'inventory.received_today', 'inventory.issued_today', 'inventory.requests_pending', 'deliveries.expected'],
                'widgets' => ['pending_requests', 'deliveries_to_receive', 'expiring_lots', 'low_stock', 'purchase_requests_to_approve', 'recent_movements', 'inventory_value'],
                'quick_actions' => ['stock_in', 'issue_stock', 'transfer_stock', 'receive_delivery', 'purchase_request', 'stock_count'],
            ],
            'accountant' => [
                'kpis' => ['payables.open', 'payables.not_invoiced', 'inventory.value', 'trace.open_batches'],
                'widgets' => ['invoices_due', 'orders_to_approve', 'inventory_value', 'recent_trace_events'],
                'quick_actions' => ['view_ledger', 'view_orders', 'view_audit_log'],
            ],
            'worker' => ['kpis' => ['tasks.today', 'tasks.done_today', 'attendance.status'], 'widgets' => ['today_tasks', 'attendance_week'], 'quick_actions' => ['my_day', 'request_leave']],
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
            'tasks.today' => ['label' => 'Tasks due today', 'format' => 'number', 'permission' => 'tasks.view',
                'value' => fn () => $this->workforce->tasksToday()],
            'tasks.completed' => ['label' => 'Tasks verified', 'format' => 'number', 'permission' => 'tasks.view',
                'value' => fn (Period $p) => $this->workforce->tasksVerified($p),
                'previous' => fn (Period $p) => $this->workforce->tasksVerified($p->previous())],
            'tasks.pending' => ['label' => 'Tasks to verify', 'format' => 'number', 'permission' => 'tasks.verify',
                'value' => fn () => $this->workforce->tasksAwaitingReview()],
            'tasks.overdue' => ['label' => 'Overdue tasks', 'format' => 'number', 'permission' => 'tasks.view',
                'value' => fn () => $this->workforce->tasksOverdue()],
            'tasks.done_today' => ['label' => 'Done today', 'format' => 'number', 'permission' => 'tasks.execute',
                'value' => fn () => $this->workforce->myDoneToday()],
            'activities.active' => ['label' => 'Open activities', 'format' => 'number', 'permission' => 'tasks.view',
                'value' => fn () => $this->workforce->activitiesOpen()],
            'workers.present' => ['label' => 'Workers present', 'format' => 'number', 'permission' => 'attendance.approve',
                'value' => fn () => $this->workforce->workersPresent(),
                'meta' => fn () => ['active_workers' => $this->workforce->workersActive()]],
            'workers.absent' => ['label' => 'Not checked in', 'format' => 'number', 'permission' => 'attendance.approve',
                'value' => fn () => $this->workforce->workersAbsent()],
            'attendance.status' => ['label' => 'Attendance', 'format' => 'status', 'permission' => 'attendance.record',
                'value' => fn () => $this->workforce->myAttendanceStatus()],
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
            'livestock.head_count' => ['label' => 'Animals', 'format' => 'number', 'permission' => 'livestock.animals.view',
                'value' => fn () => $this->livestock->headCount(), 'meta' => fn () => ['by_species' => $this->livestock->bySpecies()]],
            'livestock.new_animals' => ['label' => 'New animals', 'format' => 'number', 'permission' => 'livestock.animals.view',
                'value' => fn (Period $p) => $this->livestock->newAnimals($p),
                'previous' => fn (Period $p) => $this->livestock->newAnimals($p->previous())],
            'livestock.pregnant' => ['label' => 'Pregnant', 'format' => 'number', 'permission' => 'livestock.animals.view',
                'value' => fn () => $this->livestock->pregnant()],
            'livestock.vaccinations_due' => ['label' => 'Vaccinations due (14 days)', 'format' => 'number', 'permission' => 'livestock.animals.view',
                'value' => fn () => count($this->livestock->vaccinationsDue())],
            'livestock.under_withdrawal' => ['label' => 'Under withdrawal', 'format' => 'number', 'permission' => 'livestock.animals.view',
                'value' => fn () => $this->livestock->underWithdrawal()],
            'livestock.mortality_rate' => ['label' => 'Mortality', 'format' => 'percent', 'permission' => 'livestock.animals.view',
                'value' => fn (Period $p) => $this->livestock->mortalityRate($p)],
            'livestock.milk' => ['label' => 'Milk (period)', 'format' => 'quantity', 'permission' => 'livestock.animals.view',
                'value' => fn (Period $p) => ['value' => number_format($this->livestock->milkLitres($p), 1, '.', ''), 'unit' => 'l'],
                'previous' => fn (Period $p) => ['value' => number_format($this->livestock->milkLitres($p->previous()), 1, '.', ''), 'unit' => 'l']],
            'livestock.daily_gain' => ['label' => 'Average daily gain', 'format' => 'quantity', 'permission' => 'livestock.animals.view',
                'value' => fn () => ($g = $this->livestock->averageDailyGain()) === null ? null : ['value' => number_format($g, 2, '.', ''), 'unit' => 'kg/day']],
            'livestock.sold' => ['label' => 'Animals sold', 'format' => 'number', 'permission' => 'livestock.animals.view',
                'value' => fn (Period $p) => $this->livestock->sold($p)],
            'inventory.items' => ['label' => 'Stock items', 'format' => 'number', 'permission' => 'inventory.view',
                'value' => fn () => $this->inventory->items()],
            'inventory.low' => ['label' => 'Low stock', 'format' => 'number', 'permission' => 'inventory.view',
                'value' => fn () => $this->inventory->lowAndOut()['low']],
            'inventory.out' => ['label' => 'Out of stock', 'format' => 'number', 'permission' => 'inventory.view',
                'value' => fn () => $this->inventory->lowAndOut()['out']],
            'inventory.value' => ['label' => 'Stock value', 'format' => 'money', 'permission' => 'inventory.values.view',
                'value' => fn () => ['amount' => $this->inventory->stockValue(), 'currency' => $farm->currency]],
            'inventory.received_today' => ['label' => 'Receipts today', 'format' => 'number', 'permission' => 'inventory.view',
                'value' => fn () => $this->inventory->movedToday('in')],
            'inventory.issued_today' => ['label' => 'Issues today', 'format' => 'number', 'permission' => 'inventory.view',
                'value' => fn () => $this->inventory->movedToday('out')],
            'inventory.requests_pending' => ['label' => 'Open stock requests', 'format' => 'number', 'permission' => 'inventory.stock.move|inventory.stock.approve',
                'value' => fn () => $this->inventory->requestsPending()],
            'deliveries.expected' => ['label' => 'Orders awaiting delivery', 'format' => 'number', 'permission' => 'procurement.deliveries.receive',
                'value' => fn () => $this->inventory->deliveriesExpected()],
            'payables.open' => ['label' => 'Supplier invoices unpaid', 'format' => 'money', 'permission' => 'finance.values.view',
                'value' => fn () => ['amount' => $this->inventory->payablesOpen(), 'currency' => $farm->currency]],
            'payables.not_invoiced' => ['label' => 'Received, not invoiced', 'format' => 'money', 'permission' => 'finance.values.view',
                'value' => fn () => ['amount' => $this->inventory->receivedNotInvoiced(), 'currency' => $farm->currency]],
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
            'verification_queue' => ['type' => 'action_list', 'permission' => 'tasks.verify', 'inline' => true,
                'data' => fn () => ['items' => $this->workforce->verificationQueue()]],
            'schedule' => ['type' => 'action_list', 'permission' => 'tasks.view', 'inline' => true,
                'data' => fn () => ['items' => $this->workforce->schedule()]],
            'overdue_tasks' => ['type' => 'action_list', 'permission' => 'tasks.view', 'inline' => true,
                'data' => fn () => ['items' => $this->workforce->overdueTasks()]],
            'leave_requests' => ['type' => 'action_list', 'permission' => 'leave.approve', 'inline' => true,
                'data' => fn () => ['items' => $this->workforce->leaveRequests()]],
            'today_tasks' => ['type' => 'action_list', 'permission' => 'tasks.execute', 'inline' => true,
                'data' => fn () => ['items' => $this->workforce->myTasks()]],
            'worker_activity' => ['type' => 'chart', 'permission' => 'tasks.view', 'inline' => false,
                'data' => function (Period $p) {
                    $d = $this->workforce->verifiedPerDay($p);

                    return [
                        'chart' => 'bar',
                        'x' => ['type' => 'date', 'values' => $d['labels']],
                        'series' => [['key' => 'verified', 'label' => 'Tasks verified', 'unit' => 'tasks', 'values' => $d['verified']]],
                    ];
                }],
            'attendance_week' => ['type' => 'chart', 'permission' => 'attendance.record', 'inline' => false,
                'data' => function () {
                    $d = $this->workforce->myWeek();

                    return [
                        'chart' => 'bar',
                        'x' => ['type' => 'date', 'values' => $d['labels']],
                        'series' => [['key' => 'hours', 'label' => 'Hours worked', 'unit' => 'h', 'values' => $d['hours']]],
                    ];
                }],
            'vaccinations_due' => ['type' => 'action_list', 'permission' => 'livestock.animals.view', 'inline' => true,
                'data' => fn () => ['items' => $this->livestock->vaccinationsDue()]],
            'withdrawal_alerts' => ['type' => 'action_list', 'permission' => 'livestock.animals.view', 'inline' => true,
                'data' => fn () => ['items' => $this->livestock->withdrawalAlerts()]],
            'expected_births' => ['type' => 'action_list', 'permission' => 'livestock.animals.view', 'inline' => true,
                'data' => fn () => ['items' => $this->livestock->expectedBirths()]],
            'weight_loss_alerts' => ['type' => 'action_list', 'permission' => 'livestock.animals.view', 'inline' => true,
                'data' => fn () => ['items' => $this->livestock->weightLoss()]],
            'livestock_sale_requests' => ['type' => 'action_list', 'permission' => 'livestock.sales.approve', 'inline' => true,
                'data' => fn () => ['items' => $this->livestock->saleRequestsPending()]],
            'milk_production' => ['type' => 'chart', 'permission' => 'livestock.animals.view', 'inline' => false,
                'data' => function (Period $p) {
                    $series = $this->livestock->milkPerDay($p);

                    return [
                        'chart' => 'bar',
                        'x' => ['type' => 'date', 'values' => array_column($series, 'date')],
                        'series' => [['key' => 'milk', 'label' => 'Milk kept', 'unit' => 'l', 'values' => array_column($series, 'litres')]],
                    ];
                }],
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
            'pending_requests' => ['type' => 'action_list', 'permission' => 'inventory.stock.move|inventory.stock.approve', 'inline' => true,
                'data' => fn () => ['items' => $this->inventory->pendingRequests()]],
            'deliveries_to_receive' => ['type' => 'action_list', 'permission' => 'procurement.deliveries.receive', 'inline' => true,
                'data' => fn () => ['items' => $this->inventory->deliveriesToReceive()]],
            'expiring_lots' => ['type' => 'action_list', 'permission' => 'inventory.view', 'inline' => true,
                'data' => fn () => ['items' => $this->inventory->expiringLots()]],
            'low_stock' => ['type' => 'action_list', 'permission' => 'inventory.view', 'inline' => true,
                'data' => fn () => ['items' => $this->inventory->lowStock()]],
            'recent_movements' => ['type' => 'action_list', 'permission' => 'inventory.view', 'inline' => true,
                'data' => fn () => ['items' => $this->inventory->recentMovements()]],
            'purchase_requests_to_approve' => ['type' => 'action_list', 'permission' => 'procurement.requests.approve', 'inline' => true,
                'data' => fn () => ['items' => $this->inventory->purchaseRequestsToApprove()]],
            'orders_to_approve' => ['type' => 'action_list', 'permission' => 'procurement.orders.approve', 'inline' => true,
                'data' => fn () => ['items' => $this->inventory->ordersToApprove()]],
            'invoices_due' => ['type' => 'action_list', 'permission' => 'finance.values.view', 'inline' => true,
                'data' => fn () => ['items' => $this->inventory->invoicesDue()]],
            'inventory_value' => ['type' => 'chart', 'permission' => 'inventory.values.view', 'inline' => false,
                'data' => function () use ($farm) {
                    $d = $this->inventory->valueByCategory();

                    return [
                        'chart' => 'bar',
                        'x' => ['type' => 'category', 'values' => $d['labels']],
                        'series' => [['key' => 'value', 'label' => 'Stock value', 'unit' => $farm->currency, 'values' => $d['values']]],
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
            'new_task' => ['label' => 'Assign work', 'permission' => 'tasks.manage', 'target' => "/farms/{$id}/tasks?new=1"],
            'view_workers' => ['label' => 'Workers & attendance', 'permission' => 'workers.view', 'target' => "/farms/{$id}/workers"],
            'my_day' => ['label' => 'My day', 'permission' => 'tasks.execute', 'target' => "/farms/{$id}/my-day"],
            'request_leave' => ['label' => 'Request leave', 'permission' => 'leave.request', 'target' => "/farms/{$id}/my-day?leave=1"],
            'view_map' => ['label' => 'Farm map', 'permission' => 'structure.view', 'target' => "/farms/{$id}/structure"],
            'register_animal' => ['label' => 'Register animal', 'permission' => 'livestock.animals.manage', 'target' => "/farms/{$id}/livestock?new=animal"],
            'record_health' => ['label' => 'Treatment / vaccination', 'permission' => 'livestock.records.record', 'target' => "/farms/{$id}/livestock?action=health"],
            'record_weight' => ['label' => 'Record weight', 'permission' => 'livestock.records.record', 'target' => "/farms/{$id}/livestock?action=weight"],
            'record_production' => ['label' => 'Record milk / eggs', 'permission' => 'livestock.records.record', 'target' => "/farms/{$id}/livestock?tab=production"],
            'request_sale' => ['label' => 'Request a sale', 'permission' => 'livestock.sales.request', 'target' => "/farms/{$id}/livestock?action=sale"],
            'start_cycle' => ['label' => 'Start crop cycle', 'permission' => 'crops.plans.manage', 'target' => "/farms/{$id}/crops?new=cycle"],
            'record_operation' => ['label' => 'Record field work', 'permission' => 'crops.operations.record', 'target' => "/farms/{$id}/crops?action=operation"],
            'report_observation' => ['label' => 'Report pest / disease', 'permission' => 'crops.operations.record', 'target' => "/farms/{$id}/crops?action=observation"],
            'record_harvest' => ['label' => 'Record harvest', 'permission' => 'crops.harvest.record', 'target' => "/farms/{$id}/crops?action=harvest"],
            'new_crop_plan' => ['label' => 'New crop plan', 'permission' => 'crops.plans.manage', 'target' => "/farms/{$id}/crops?tab=plans&new=plan"],
            'new_batch' => ['label' => 'New batch', 'permission' => 'trace.batches.create', 'target' => "/farms/{$id}/traceability/batches/new"],
            'stock_in' => ['label' => 'Stock in', 'permission' => 'inventory.stock.move', 'target' => "/farms/{$id}/inventory?action=stock-in"],
            'issue_stock' => ['label' => 'Issue stock', 'permission' => 'inventory.stock.move', 'target' => "/farms/{$id}/inventory?action=issue"],
            'transfer_stock' => ['label' => 'Transfer', 'permission' => 'inventory.stock.move', 'target' => "/farms/{$id}/inventory?action=transfer"],
            'stock_count' => ['label' => 'Stock count', 'permission' => 'inventory.stock.adjust', 'target' => "/farms/{$id}/inventory?action=count"],
            'receive_delivery' => ['label' => 'Receive delivery', 'permission' => 'procurement.deliveries.receive', 'target' => "/farms/{$id}/procurement?tab=orders&status=open"],
            'purchase_request' => ['label' => 'Purchase request', 'permission' => 'procurement.requests.create', 'target' => "/farms/{$id}/procurement?tab=requests&new=1"],
            'view_orders' => ['label' => 'Purchase orders', 'permission' => 'procurement.orders.manage', 'target' => "/farms/{$id}/procurement"],
            'view_ledger' => ['label' => 'Ledger', 'permission' => 'finance.view', 'target' => "/farms/{$id}/ledger"],
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
            ] + (isset($def['meta']) ? ['meta' => ($def['meta'])($period)] : []);
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

    /**
     * Cache key part: two members see the same data only with the same
     * permissions and scopes. Anything narrower than `all` (own tasks, own
     * attendance) is personal, so the member is part of the key.
     */
    public function permissionFingerprint(): string
    {
        $grants = array_map(fn ($scope) => $scope->value, $this->permissions->current());
        ksort($grants);
        $personal = array_filter($grants, fn ($scope) => $scope !== 'all') !== [];

        return substr(hash('sha256', json_encode($grants)), 0, 16).($personal ? ':u:'.Auth::id() : '');
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
        if ($permission === null) {
            return true;
        }

        foreach (explode('|', $permission) as $alternative) {
            if ($this->permissions->allows($alternative)) {
                return true;
            }
        }

        return false;
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
