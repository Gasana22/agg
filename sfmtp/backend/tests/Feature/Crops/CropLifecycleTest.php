<?php

namespace Tests\Feature\Crops;

use App\Modules\Access\Application\RoleService;
use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\Crops\Domain\Models\CropCycle;
use App\Modules\Crops\Domain\Models\CropHarvest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Domain\Models\TraceEvent;
use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CropLifecycleTest extends TestCase
{
    private Farm $farm;

    private User $owner;

    private User $agronomist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->farm = $this->farm();
        $this->owner = $this->ownerOf($this->farm);
        $this->agronomist = $this->memberWithRole($this->farm, 'agronomist');
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}{$path}";
    }

    private function plot(string $name = 'Plot 1', float $ha = 2.0): string
    {
        return $this->asUser($this->owner)->postJson($this->url('/structure/plots'), ['name' => $name, 'declared_area_ha' => $ha])->assertCreated()->json('data.id');
    }

    private function maize(): string
    {
        $variety = DB::table('global_crop_varieties')->where('code', 'longe_5')->value('id');

        return $this->asUser($this->agronomist)->postJson($this->url('/crops'), ['global_variety_id' => $variety])
            ->assertCreated()
            ->assertJsonPath('data.label', 'Maize (Longe 5)')
            ->assertJsonPath('data.maturity_days', 115)
            ->json('data.id');
    }

    private function season(): string
    {
        return $this->asUser($this->agronomist)->postJson($this->url('/seasons'), ['name' => '2026 A', 'starts_on' => '2026-03-01', 'ends_on' => '2026-07-31'])
            ->assertCreated()->json('data.id');
    }

    private function seedLot(): TraceBatch
    {
        return $this->inFarm($this->farm, fn () => $this->app->make(Recorder::class)->createBatch(BatchKind::SeedLot, ['name' => 'Longe 5 seed', 'quantity' => '25', 'unit' => 'kg']));
    }

    /** @return array<int,string> event types on a batch, oldest first */
    private function events(string $batchId): array
    {
        return $this->inFarm($this->farm, fn () => TraceEvent::where('batch_id', $batchId)->orderBy('farm_seq')->pluck('event_type')->all());
    }

    private function startCycle(array $extra = []): array
    {
        return $this->asUser($this->agronomist)->postJson($this->url('/crop-cycles'), $extra + [
            'plot_id' => $this->plot(), 'crop_id' => $this->maize(), 'planted_on' => now()->subDays(100)->toDateString(),
        ])->assertCreated()->json('data');
    }

    public function test_plan_to_harvest_journey_with_traceability(): void
    {
        $crop = $this->maize();
        $season = $this->season();
        $plot = $this->plot('B-3', 2.5);

        // Plan: drafted by the agronomist, approved by the owner.
        $plan = $this->asUser($this->agronomist)->postJson($this->url('/crop-plans'), [
            'name' => 'Maize B-3', 'season_id' => $season, 'crop_id' => $crop, 'planned_area_ha' => 2.5, 'expected_yield' => 10000,
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.code', 'CP-001')->json('data');

        $this->assertProblem($this->asUser($this->agronomist)->postJson($this->url("/crop-plans/{$plan['id']}/approve")), 403, 'forbidden');
        $this->assertProblem(
            $this->asUser($this->agronomist)->postJson($this->url('/crop-cycles'), ['plot_id' => $plot, 'crop_id' => $crop, 'plan_id' => $plan['id']]),
            422, 'plan_not_approved',
        );
        $this->asUser($this->owner)->postJson($this->url("/crop-plans/{$plan['id']}/approve"))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertProblem($this->asUser($this->agronomist)->patchJson($this->url("/crop-plans/{$plan['id']}"), ['name' => 'Late edit']), 409, 'invalid_state_transition');

        // Cycle from a seed lot: the crop lot is derived from it and planted.
        $seed = $this->seedLot();
        $cycle = $this->asUser($this->agronomist)->postJson($this->url('/crop-cycles'), [
            'plot_id' => $plot, 'crop_id' => $crop, 'plan_id' => $plan['id'], 'seed_batch_id' => $seed->id,
            'planted_on' => now()->subDays(100)->toDateString(), 'expected_yield' => 10000,
        ])->assertCreated()
            ->assertJsonPath('data.stage', 'planted')
            ->assertJsonPath('data.area_ha', 2.5)
            ->assertJsonPath('data.expected_harvest_on', now()->subDays(100)->addDays(115)->toDateString())
            ->assertJsonPath('meta.warnings', [])
            ->json('data');
        $lot = $cycle['crop_lot']['id'];
        $this->assertSame(['created', 'linked_from', 'planted'], $this->events($lot));
        $this->asUser($this->owner)->getJson($this->url("/crop-plans/{$plan['id']}"))->assertJsonPath('data.status', 'active')->assertJsonPath('data.cycles_count', 1);

        // A spray against fall armyworm with a 14-day withholding period.
        $observation = $this->asUser($this->agronomist)->postJson($this->url('/crop-observations'), [
            'cycle_id' => $cycle['id'], 'kind' => 'pest', 'severity' => 'high', 'title' => 'Fall armyworm', 'affected_pct' => 15,
        ])->assertCreated()->assertJsonPath('data.status', 'open')->json('data');

        $sprayedAt = now()->subDays(5);
        $this->asUser($this->agronomist)->postJson($this->url('/crop-operations'), [
            'cycle_id' => $cycle['id'], 'type' => 'spraying', 'occurred_at' => $sprayedAt->toIso8601String(), 'observation_id' => $observation['id'],
            'inputs' => [['product_name' => 'Emamectin benzoate 5% SG', 'quantity' => 0.4, 'unit' => 'kg', 'withholding_days' => 14]],
        ])->assertCreated()->assertJsonPath('data.status', 'verified');

        $this->assertSame(['created', 'linked_from', 'planted', 'observation', 'operation', 'input_applied'], $this->events($lot));
        $safe = $sprayedAt->setTimezone('Africa/Kampala')->startOfDay()->addDays(14)->toDateString();
        $this->asUser($this->agronomist)->getJson($this->url("/crop-cycles/{$cycle['id']}"))->assertJsonPath('data.safe_harvest_on', $safe);

        // Harvesting inside the withholding period needs an override reason.
        $harvest = ['cycle_id' => $cycle['id'], 'harvested_on' => now()->toDateString(), 'quantity' => 40, 'unit' => 'bag_100kg', 'quality_grade' => 'A'];
        $this->assertProblem($this->asUser($this->agronomist)->postJson($this->url('/harvests'), $harvest), 422, 'withholding_period');
        $first = $this->asUser($this->agronomist)->postJson($this->url('/harvests'), $harvest + ['withholding_override_reason' => 'Lab residue test passed'])
            ->assertCreated()->assertJsonPath('data.quantity', 40)->json('data');
        $this->asUser($this->agronomist)->postJson($this->url('/harvests'), ['cycle_id' => $cycle['id'], 'harvested_on' => now()->toDateString(), 'quantity' => 1500, 'unit' => 'kg', 'withholding_override_reason' => 'Same lab test'])->assertCreated();

        $shown = $this->asUser($this->agronomist)->getJson($this->url("/crop-cycles/{$cycle['id']}"))->assertJsonPath('data.stage', 'harvesting')->json('data');
        $this->assertEqualsWithDelta(5500.0, $shown['actual_yield'], 0.001);   // 40 × 100 kg + 1,500 kg

        // Backward journey from the harvest reaches the seed lot.
        $journey = $this->asUser($this->agronomist)->getJson($this->url("/traceability/batches/{$first['batch']['id']}/journey?direction=backward"))->assertOk()->json('data');
        $this->assertContains($seed->id, array_column($journey['backward']['nodes'], 'id'));
        $this->assertContains('withholding_override', array_keys($this->inFarm($this->farm, fn () => TraceEvent::where('batch_id', $first['batch']['id'])->where('event_type', 'harvested')->first()->payload)));

        // Resolve the pest, close the cycle, then the plan.
        $this->asUser($this->agronomist)->patchJson($this->url("/crop-observations/{$observation['id']}"), ['status' => 'resolved', 'resolution_note' => 'No live larvae after spray'])
            ->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->assertProblem($this->asUser($this->agronomist)->postJson($this->url("/crop-plans/{$plan['id']}/close")), 409, 'has_open_cycles');
        $this->asUser($this->agronomist)->postJson($this->url("/crop-cycles/{$cycle['id']}/close"), ['reason' => 'harvested'])
            ->assertOk()->assertJsonPath('data.stage', 'closed')->assertJsonPath('data.crop_lot.status', 'closed');
        $this->asUser($this->agronomist)->postJson($this->url("/crop-plans/{$plan['id']}/close"))->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertProblem($this->asUser($this->agronomist)->postJson($this->url('/harvests'), $harvest), 409, 'invalid_state_transition');
    }

    public function test_transplanted_crops_go_through_the_nursery(): void
    {
        $cycle = $this->asUser($this->agronomist)->postJson($this->url('/crop-cycles'), [
            'plot_id' => $this->plot(), 'crop_id' => $this->maize(), 'planting_method' => 'transplant',
            'sown_on' => now()->subDays(30)->toDateString(), 'seeds_sown' => 5000, 'seed_batch_id' => $this->seedLot()->id,
        ])->assertCreated()
            ->assertJsonPath('data.stage', 'nursery')
            ->assertJsonPath('data.crop_lot', null)
            ->assertJsonPath('data.nursery.seeds_sown', 5000)
            ->json('data');

        $this->assertProblem($this->asUser($this->agronomist)->postJson($this->url('/harvests'), ['cycle_id' => $cycle['id'], 'harvested_on' => now()->toDateString(), 'quantity' => 1, 'unit' => 'kg']), 409, 'invalid_state_transition');

        $moved = $this->asUser($this->agronomist)->postJson($this->url("/crop-cycles/{$cycle['id']}/transplant"), [
            'planted_on' => now()->toDateString(), 'seedlings_germinated' => 4600, 'seedlings_transplanted' => 4400,
        ])->assertOk()
            ->assertJsonPath('data.stage', 'planted')
            ->assertJsonPath('data.nursery_batch.status', 'closed')
            ->assertJsonPath('data.nursery.seedlings_transplanted', 4400)
            ->json('data');

        $parents = $this->inFarm($this->farm, fn () => DB::table('trace_batch_links')->where('child_batch_id', $moved['crop_lot']['id'])->pluck('parent_batch_id')->all());
        $this->assertSame([$cycle['nursery_batch']['id']], $parents);

        $this->asUser($this->agronomist)->postJson($this->url("/crop-cycles/{$cycle['id']}/stage"), ['stage' => 'growing'])->assertOk()->assertJsonPath('data.stage', 'growing');
        $this->assertProblem($this->asUser($this->agronomist)->postJson($this->url("/crop-cycles/{$cycle['id']}/stage"), ['stage' => 'growing']), 409, 'invalid_state_transition');
    }

    public function test_one_open_cycle_per_plot_unless_intercropping_is_allowed(): void
    {
        $plot = $this->plot();
        $crop = $this->maize();
        $this->asUser($this->agronomist)->postJson($this->url('/crop-cycles'), ['plot_id' => $plot, 'crop_id' => $crop])->assertCreated();

        $this->assertProblem($this->asUser($this->agronomist)->postJson($this->url('/crop-cycles'), ['plot_id' => $plot, 'crop_id' => $crop]), 409, 'plot_occupied');

        $this->asUser($this->owner)->patchJson($this->url('/settings'), ['allow_intercropping' => true])->assertOk();
        $this->asUser($this->agronomist)->postJson($this->url('/crop-cycles'), ['plot_id' => $plot, 'crop_id' => $crop, 'area_ha' => 5])
            ->assertCreated()->assertJsonPath('meta.warnings.0.code', 'area_exceeds_plot');
    }

    public function test_work_by_members_who_cannot_verify_waits_for_verification(): void
    {
        $cycle = $this->startCycle();

        // A "Crop Scout" records crop work but cannot verify it.
        $scoutRole = $this->inFarm($this->farm, fn () => $this->app->make(RoleService::class)->createRole([
            'name' => 'Crop Scout',
            'grants' => ['crops.plans.view' => 'all', 'crops.operations.view' => 'own', 'crops.operations.record' => 'all'],
        ]));
        $scout = $this->memberWithRole($this->farm, $scoutRole->key);

        $operation = $this->asUser($scout)->postJson($this->url('/crop-operations'), [
            'cycle_id' => $cycle['id'], 'type' => 'fertilizing',
            'inputs' => [['product_name' => 'CAN 26%', 'quantity' => 100, 'unit' => 'kg']],
        ])->assertCreated()->assertJsonPath('data.status', 'recorded')->json('data');
        $this->assertSame(['created', 'planted'], $this->events($cycle['crop_lot']['id']));

        // Scope `own`: the scout lists only their own work.
        $this->asUser($this->agronomist)->postJson($this->url('/crop-operations'), ['cycle_id' => $cycle['id'], 'type' => 'weeding'])->assertCreated();
        $this->asUser($scout)->getJson($this->url('/crop-operations'))->assertJsonCount(1, 'data');
        $this->asUser($this->agronomist)->getJson($this->url('/crop-operations?filter[status]=recorded'))->assertJsonCount(1, 'data');

        $this->asUser($this->agronomist)->postJson($this->url("/crop-operations/{$operation['id']}/verify"))
            ->assertOk()->assertJsonPath('data.status', 'verified')->assertJsonPath('data.verified_by.id', $this->agronomist->id);
        $this->assertSame(['created', 'planted', 'operation', 'operation', 'input_applied'], $this->events($cycle['crop_lot']['id']));
        $this->assertProblem($this->asUser($this->agronomist)->postJson($this->url("/crop-operations/{$operation['id']}/verify")), 409, 'invalid_state_transition');

        $second = $this->asUser($scout)->postJson($this->url('/crop-operations'), ['cycle_id' => $cycle['id'], 'type' => 'irrigation'])->json('data');
        $this->asUser($this->agronomist)->postJson($this->url("/crop-operations/{$second['id']}/reject"), ['reason' => 'Plot was flooded that day'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_role_boundaries_and_money_fields(): void
    {
        $cycle = $this->startCycle();
        $season = $this->season();
        $plan = $this->asUser($this->owner)->postJson($this->url('/crop-plans'), [
            'name' => 'Budgeted', 'season_id' => $season, 'crop_id' => $cycle['crop']['id'], 'planned_area_ha' => 1, 'budget_amount' => 2500000,
        ])->assertCreated()->assertJsonPath('data.budget_amount', 2500000)->json('data');
        $this->asUser($this->owner)->postJson($this->url('/crop-operations'), ['cycle_id' => $cycle['id'], 'type' => 'weeding', 'cost_amount' => 120000])
            ->assertCreated()->assertJsonPath('data.cost_amount', 120000);

        // The agronomist sees quantities but never money, and cannot write it.
        $this->asUser($this->agronomist)->getJson($this->url("/crop-plans/{$plan['id']}"))->assertOk()->assertJsonMissingPath('data.budget_amount');
        $this->asUser($this->agronomist)->getJson($this->url('/crop-operations'))->assertOk()->assertJsonMissingPath('data.0.cost_amount');
        $this->assertProblem($this->asUser($this->agronomist)->postJson($this->url('/crop-operations'), ['cycle_id' => $cycle['id'], 'type' => 'weeding', 'cost_amount' => 5]), 403, 'money_field_forbidden');

        // Accountant: sees money, cannot change operational records (docs/04 §6 #8).
        $accountant = $this->memberWithRole($this->farm, 'accountant');
        $this->asUser($accountant)->getJson($this->url('/crop-operations'))->assertOk()->assertJsonPath('data.0.cost_amount', 120000);
        $this->assertProblem($this->asUser($accountant)->postJson($this->url('/crop-operations'), ['cycle_id' => $cycle['id'], 'type' => 'weeding']), 403, 'forbidden');
        $this->assertProblem($this->asUser($accountant)->postJson($this->url("/crop-cycles/{$cycle['id']}/close"), ['reason' => 'failed']), 403, 'forbidden');

        // Livestock manager: no crops (docs/04 §6 #6).
        $livestock = $this->memberWithRole($this->farm, 'livestock_manager');
        $this->assertProblem($this->asUser($livestock)->getJson($this->url('/crop-cycles')), 403, 'forbidden');
        $this->assertProblem($this->asUser($livestock)->postJson($this->url('/crop-operations'), ['cycle_id' => $cycle['id'], 'type' => 'weeding']), 403, 'forbidden');

        // Store manager: sees harvests only.
        $store = $this->memberWithRole($this->farm, 'store_manager');
        $this->asUser($store)->getJson($this->url('/harvests'))->assertOk();
        $this->assertProblem($this->asUser($store)->getJson($this->url('/crop-cycles')), 403, 'forbidden');

        // Field worker: records only on assigned cycles (tasks arrive in Phase 6).
        $worker = $this->memberWithRole($this->farm, 'field_worker');
        $this->assertProblem($this->asUser($worker)->postJson($this->url('/crop-operations'), ['cycle_id' => $cycle['id'], 'type' => 'weeding']), 403, 'not_assigned');
        $this->assertProblem($this->asUser($worker)->postJson($this->url('/harvests'), ['cycle_id' => $cycle['id'], 'harvested_on' => now()->toDateString(), 'quantity' => 1, 'unit' => 'kg']), 403, 'not_assigned');
    }

    public function test_agronomist_dashboard_reports_crop_work(): void
    {
        $cycle = $this->startCycle(['expected_yield' => 3000, 'expected_harvest_on' => now()->addDays(5)->toDateString()]);
        $this->asUser($this->agronomist)->postJson($this->url('/crop-observations'), ['cycle_id' => $cycle['id'], 'kind' => 'disease', 'severity' => 'critical', 'title' => 'Maize lethal necrosis'])->assertCreated();
        $this->asUser($this->agronomist)->postJson($this->url('/harvests'), ['cycle_id' => $cycle['id'], 'harvested_on' => now()->toDateString(), 'quantity' => 12, 'unit' => 'bag_100kg'])->assertCreated();

        $data = $this->asUser($this->agronomist)->getJson($this->url('/dashboards/agronomist?period=7d'))->assertOk()->json('data');
        $kpis = collect($data['kpis'])->keyBy('key');
        $this->assertSame(1, $kpis['crop.active_cycles']['value']);
        $this->assertSame(['value' => '2.00', 'unit' => 'ha'], $kpis['crop.planted_area']['value']);
        $this->assertSame(1, $kpis['crop.near_harvest']['value']);
        $this->assertSame(['value' => '1200.0', 'unit' => 'kg'], $kpis['crop.actual_yield']['value']);
        $this->assertSame(['value' => '600.0', 'unit' => 'kg/ha'], $kpis['crop.yield_per_ha']['value']);
        $this->assertSame('up', $kpis['crop.actual_yield']['delta']['direction']);
        $this->assertSame(1, $kpis['crop.incidents_open']['value']);

        $widgets = collect($data['widgets'])->keyBy('key');
        $this->assertSame('Critical', $widgets['pest_disease_alerts']['data']['items'][0]['badge']['label']);
        $this->assertCount(1, $widgets['upcoming_harvests']['data']['items']);

        $chart = $this->asUser($this->agronomist)->getJson($this->url('/dashboards/agronomist/widgets/expected_vs_actual_yield?period=7d'))->assertOk()->json('data');
        $this->assertSame(['expected', 'actual'], array_column($chart['series'], 'key'));
        $this->assertSame([3000, 1200], [(int) $chart['series'][0]['values'][0], (int) $chart['series'][1]['values'][0]]);
    }

    public function test_harvests_are_append_only(): void
    {
        $cycle = $this->startCycle();
        $this->asUser($this->agronomist)->postJson($this->url('/harvests'), ['cycle_id' => $cycle['id'], 'harvested_on' => now()->toDateString(), 'quantity' => 900, 'unit' => 'kg'])->assertCreated();

        $this->expectException(QueryException::class);
        $this->inFarm($this->farm, fn () => DB::table('crop_harvests')->update(['quantity' => 9000]));
    }

    public function test_model_refuses_to_change_a_harvest(): void
    {
        $cycle = $this->startCycle();
        $this->asUser($this->agronomist)->postJson($this->url('/harvests'), ['cycle_id' => $cycle['id'], 'harvested_on' => now()->toDateString(), 'quantity' => 900, 'unit' => 'kg'])->assertCreated();

        $this->inFarm($this->farm, function () {
            $harvest = CropHarvest::firstOrFail();
            $this->expectException(AppendOnlyViolation::class);
            $harvest->update(['quantity' => 1]);
        });
    }

    public function test_references_must_belong_to_the_farm(): void
    {
        $other = $this->farm();
        $foreignPlot = $this->asUser($this->ownerOf($other))->postJson("/api/v1/farms/{$other->id}/structure/plots", ['name' => 'Theirs', 'declared_area_ha' => 1])->json('data.id');
        $foreignSeed = $this->inFarm($other, fn () => $this->app->make(Recorder::class)->createBatch(BatchKind::SeedLot));

        $crop = $this->maize();
        $this->asUser($this->agronomist)->postJson($this->url('/crop-cycles'), ['plot_id' => $foreignPlot, 'crop_id' => $crop])
            ->assertStatus(422)->assertJsonValidationErrors('plot_id');
        $this->asUser($this->agronomist)->postJson($this->url('/crop-cycles'), ['plot_id' => $this->plot(), 'crop_id' => $crop, 'seed_batch_id' => $foreignSeed->id])
            ->assertStatus(422)->assertJsonValidationErrors('seed_batch_id');

        // The database refuses a cycle on another farm's plot even if the service were bypassed.
        $this->expectException(QueryException::class);
        $this->inFarm($this->farm, fn () => CropCycle::query()->insert([
            'id' => (string) Str::uuid7(), 'farm_id' => $this->farm->id, 'code' => 'CC-X', 'plot_id' => $foreignPlot,
            'crop_id' => $crop, 'stage' => 'planted', 'area_ha' => 1, 'planting_method' => 'direct', 'yield_unit' => 'kg', 'version' => 1,
        ]));
    }
}
