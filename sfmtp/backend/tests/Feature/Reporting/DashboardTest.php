<?php

namespace Tests\Feature\Reporting;

use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->farm = $this->farm(attributes: ['size_ha' => 120]);
        $this->inFarm($this->farm, function () {
            $recorder = $this->app->make(Recorder::class);
            $recorder->createBatch(BatchKind::SeedLot);
            $recorder->createBatch(BatchKind::InputLot);
        });
    }

    private function dashboard(string $key, $user, string $query = '')
    {
        return $this->asUser($user)->getJson("/api/v1/farms/{$this->farm->id}/dashboards/{$key}{$query}");
    }

    public function test_owner_dashboard_follows_the_contract(): void
    {
        $data = $this->dashboard('owner', $this->ownerOf($this->farm), '?period=7d')->assertOk()->json('data');

        $this->assertSame('owner', $data['dashboard']);
        $this->assertSame('7d', $data['period']['key']);
        $this->assertSame(['farm.area', 'finance.revenue', 'finance.expenses', 'finance.net_profit', 'approvals.pending', 'finance.receivables', 'finance.payables', 'inventory.value', 'structure.mapped_area', 'crop.active_cycles', 'crop.actual_yield', 'livestock.head_count', 'livestock.milk', 'workers.present', 'tasks.pending', 'farm.members', 'trace.open_batches', 'trace.events', 'trace.qr_scans'], array_column($data['kpis'], 'key'));

        $kpis = collect($data['kpis'])->keyBy('key');
        $this->assertSame(['value' => '120.0000', 'unit' => 'ha'], $kpis['farm.area']['value']);
        $this->assertSame(2, $kpis['trace.open_batches']['value']);
        $this->assertSame(2, $kpis['trace.events']['value']);
        $this->assertSame('up', $kpis['trace.events']['delta']['direction']);

        $widgets = collect($data['widgets'])->keyBy('key');
        $this->assertTrue($widgets['setup_checklist']['inline']);
        $this->assertCount(2, $widgets['recent_trace_events']['data']['items']);
        $this->assertFalse($widgets['trace_activity']['inline']);
        $this->assertStringContainsString('/widgets/trace_activity?period=7d', $widgets['trace_activity']['href']);

        $this->assertSame(['view_pnl', 'invite_member', 'view_map', 'new_batch', 'view_audit_log'], array_column($data['quick_actions'], 'key'));
    }

    public function test_chart_widgets_are_fetched_separately(): void
    {
        $this->asUser($this->ownerOf($this->farm))
            ->getJson("/api/v1/farms/{$this->farm->id}/dashboards/owner/widgets/trace_activity?period=7d")
            ->assertOk()
            ->assertJsonPath('data.type', 'chart')
            ->assertJsonCount(7, 'data.x.values')
            ->assertJsonPath('data.series.0.key', 'events');

        $this->asUser($this->ownerOf($this->farm))
            ->getJson("/api/v1/farms/{$this->farm->id}/dashboards/owner/widgets/nope")
            ->assertNotFound();
    }

    public function test_each_role_gets_its_own_dashboard_trimmed_to_its_permissions(): void
    {
        $agronomist = $this->memberWithRole($this->farm, 'agronomist');
        $data = $this->dashboard('agronomist', $agronomist)->assertOk()->json('data');
        $this->assertSame(['crop.active_cycles', 'crop.planted_area', 'crop.near_harvest', 'crop.expected_yield', 'crop.actual_yield', 'crop.yield_per_ha', 'crop.incidents_open', 'crop.treatments_active'], array_column($data['kpis'], 'key'));
        $this->assertSame(['start_cycle', 'record_operation', 'report_observation', 'record_harvest', 'new_task', 'new_crop_plan', 'view_map'], array_column($data['quick_actions'], 'key'));

        $worker = $this->memberWithRole($this->farm, 'field_worker');
        $mine = $this->dashboard('worker', $worker)->assertOk()->json('data');
        $this->assertSame(['tasks.today', 'tasks.done_today', 'attendance.status'], array_column($mine['kpis'], 'key'));
        $this->assertSame(['today_tasks', 'attendance_week'], array_column($mine['widgets'], 'key'));
        $this->assertNull($mine['kpis'][2]['value']);   // no worker profile yet

        $this->assertProblem($this->dashboard('owner', $agronomist), 403, 'dashboard_not_available');
        $this->assertProblem($this->dashboard('spaceship', $agronomist), 404, 'not_found');
    }

    public function test_store_manager_and_accountant_see_stock_and_money_by_permission(): void
    {
        $store = $this->memberWithRole($this->farm, 'store_manager');
        $data = $this->dashboard('store', $store)->assertOk()->json('data');
        $this->assertSame(['inventory.items', 'inventory.low', 'inventory.out', 'inventory.value', 'inventory.received_today', 'inventory.issued_today', 'inventory.requests_pending', 'deliveries.expected'], array_column($data['kpis'], 'key'));
        $this->assertSame(['amount' => '0.00', 'currency' => $this->farm->currency], collect($data['kpis'])->firstWhere('key', 'inventory.value')['value']);
        $this->assertSame(['stock_in', 'issue_stock', 'transfer_stock', 'receive_delivery', 'purchase_request', 'stock_count'], array_column($data['quick_actions'], 'key'));

        $accountant = $this->memberWithRole($this->farm, 'accountant');
        $books = $this->dashboard('accountant', $accountant)->assertOk()->json('data');
        $this->assertSame(['finance.revenue', 'finance.expenses', 'finance.net_profit', 'finance.cash_balance', 'finance.receivables', 'finance.payables', 'finance.not_invoiced', 'payroll.current', 'budget.total', 'budget.variance', 'inventory.value'], array_column($books['kpis'], 'key'));
        $this->assertSame(['expenses_to_approve', 'invoices_due', 'customer_invoices_overdue', 'payroll_pending', 'recent_transactions', 'income_vs_expenses', 'budget_vs_actual', 'cash_flow_forecast', 'inventory_value'], array_column($books['widgets'], 'key'));
        $this->assertSame(['record_expense', 'record_income', 'new_invoice', 'pay_supplier', 'receive_payment', 'run_payroll', 'new_budget', 'view_pnl', 'view_cash_flow', 'view_ledger'], array_column($books['quick_actions'], 'key'));
        foreach (['income_vs_expenses', 'budget_vs_actual', 'cash_flow_forecast'] as $chart) {
            $this->asUser($accountant)->getJson("/api/v1/farms/{$this->farm->id}/dashboards/accountant/widgets/{$chart}")->assertOk()->assertJsonPath('data.chart', 'bar');
        }
    }

    public function test_periods_are_validated(): void
    {
        $owner = $this->ownerOf($this->farm);

        $this->dashboard('owner', $owner, '?period=forever')->assertUnprocessable();
        $this->dashboard('owner', $owner, '?period=custom&from=2026-01-01&to=2026-01-31')->assertOk()
            ->assertJsonPath('data.period', ['key' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31']);
    }

    public function test_admin_dashboard_counts_farms_without_touching_farm_data(): void
    {
        $admin = $this->platformAdmin();
        $this->farm()->forceFill(['status' => 'pending'])->save();

        $data = $this->asUser($admin)->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
        $kpis = collect($data['kpis'])->keyBy('key');

        $this->assertSame(2, $kpis['farms.registered']['value']);
        $this->assertSame(1, $kpis['farms.pending']['value']);
        $this->assertSame('ok', $kpis['health.database']['value']);
        $this->assertSame(2, $kpis['subs.trialing']['value']);
        $this->assertSame(1, collect($data['widgets'])->firstWhere('key', 'farm_approvals')['data']['total']);
    }

    public function test_admin_dashboard_is_trimmed_to_the_platform_role(): void
    {
        $billing = $this->asUser($this->platformAdmin(['billing']))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
        $this->assertContains('platform.mrr', array_column($billing['kpis'], 'key'));
        $this->assertNotContains('health.database', array_column($billing['kpis'], 'key'));

        $support = $this->asUser($this->platformAdmin(['support']))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
        $this->assertContains('tickets.open', array_column($support['kpis'], 'key'));
        $this->assertNotContains('approve_farm', array_column($support['quick_actions'], 'key'));
        $this->assertNotContains('manage_plans', array_column($support['quick_actions'], 'key'));
    }
}
