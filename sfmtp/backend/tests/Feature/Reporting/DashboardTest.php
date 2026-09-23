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
        $this->assertSame(['farm.area', 'farm.members', 'trace.open_batches', 'trace.events'], array_column($data['kpis'], 'key'));

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

        $this->assertSame(['invite_member', 'new_batch', 'view_audit_log'], array_column($data['quick_actions'], 'key'));
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
        $this->assertSame(['trace.open_batches', 'trace.events'], array_column($data['kpis'], 'key'));
        $this->assertSame(['new_batch'], array_column($data['quick_actions'], 'key'));

        $worker = $this->memberWithRole($this->farm, 'field_worker');
        $this->dashboard('worker', $worker)->assertOk()->assertJsonPath('data.kpis', [])->assertJsonPath('data.widgets', []);

        $this->assertProblem($this->dashboard('owner', $agronomist), 403, 'dashboard_not_available');
        $this->assertProblem($this->dashboard('spaceship', $agronomist), 404, 'not_found');
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
        $admin = $this->withMfa($this->member(['user_type' => 'platform_admin']));
        $this->farm()->forceFill(['status' => 'pending'])->save();

        $data = $this->asUser($admin)->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
        $kpis = collect($data['kpis'])->keyBy('key');

        $this->assertSame(2, $kpis['farms.registered']['value']);
        $this->assertSame(1, $kpis['farms.pending']['value']);
        $this->assertSame('ok', $kpis['health.db']['value']);
        $this->assertSame(1, $data['widgets'][0]['data']['total']);
    }
}
