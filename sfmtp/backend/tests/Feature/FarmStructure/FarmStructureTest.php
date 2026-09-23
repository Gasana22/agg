<?php

namespace Tests\Feature\FarmStructure;

use App\Modules\FarmStructure\Domain\Models\Block;
use App\Modules\FarmStructure\Domain\Models\Plot;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FarmStructureTest extends TestCase
{
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->farm = $this->farm();
    }

    /** A square polygon of $size degrees with its south-west corner at ($lng, $lat). */
    public static function square(float $lng, float $lat, float $size): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[
            [$lng, $lat], [$lng + $size, $lat], [$lng + $size, $lat + $size], [$lng, $lat + $size], [$lng, $lat],
        ]]];
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/farms/{$this->farm->id}/structure{$path}";
    }

    private function owner()
    {
        return $this->asUser($this->ownerOf($this->farm));
    }

    public function test_owner_builds_block_section_plot_hierarchy_with_computed_geometry(): void
    {
        $block = $this->owner()->postJson($this->url('/blocks'), [
            'name' => 'North block', 'boundary' => self::square(32.5, 0.3, 0.01),
        ])->assertCreated()
            ->assertJsonPath('data.code', 'B001')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('meta.warnings', [])
            ->json('data');

        // 0.01° × 0.01° at the equator is about 123.9 ha on the WGS 84 sphere.
        $this->assertEqualsWithDelta(123.9, $block['area_ha'], 0.5);
        $this->assertEqualsWithDelta(0.305, $block['centroid']['lat'], 1e-6);
        $this->assertEqualsWithDelta(32.505, $block['centroid']['lng'], 1e-6);

        $section = $this->owner()->postJson($this->url('/sections'), [
            'name' => 'Maize section', 'code' => 'n-s1', 'block_id' => $block['id'],
            'boundary' => self::square(32.501, 0.301, 0.004),
        ])->assertCreated()->assertJsonPath('data.code', 'N-S1')->json('data');

        $plot = $this->owner()->postJson($this->url('/plots'), [
            'name' => 'Plot 1', 'section_id' => $section['id'], 'land_use' => 'crop', 'irrigation' => 'drip',
            'boundary' => self::square(32.502, 0.302, 0.001),
        ])->assertCreated()
            ->assertJsonPath('data.irrigation', 'drip')
            ->assertJsonPath('meta.warnings', [])
            ->json('data');

        $this->owner()->postJson($this->url('/locations'), [
            'name' => 'Main store', 'kind' => 'store', 'plot_id' => $plot['id'], 'latitude' => 0.3025, 'longitude' => 32.5025,
        ])->assertCreated()->assertJsonPath('data.kind', 'store');

        $this->owner()->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'data.blocks')
            ->assertJsonCount(1, 'data.sections')
            ->assertJsonCount(1, 'data.plots')
            ->assertJsonCount(1, 'data.locations')
            ->assertJsonPath('data.totals.plots_mapped', 1)
            ->assertJsonPath('data.plots.0.section_id', $section['id']);
    }

    public function test_geometry_problems_from_gps_are_warnings_not_failures(): void
    {
        $block = $this->owner()->postJson($this->url('/blocks'), ['name' => 'B', 'boundary' => self::square(30, 0, 0.01)])->json('data');
        $section = $this->owner()->postJson($this->url('/sections'), ['name' => 'S', 'block_id' => $block['id'], 'boundary' => self::square(30, 0, 0.005)])->json('data');

        $first = $this->owner()->postJson($this->url('/plots'), ['name' => 'P1', 'section_id' => $section['id'], 'boundary' => self::square(30.001, 0.001, 0.002)])
            ->assertCreated()->json('data');

        // Overlaps P1 and pokes outside the section: saved, with two warnings.
        $response = $this->owner()->postJson($this->url('/plots'), ['name' => 'P2', 'section_id' => $section['id'], 'boundary' => self::square(30.002, 0.002, 0.004)])
            ->assertCreated();
        $codes = collect($response->json('meta.warnings'))->pluck('code')->sort()->values()->all();
        $this->assertSame(['outside_parent', 'overlaps_sibling'], $codes);
        $this->assertContains($first['id'], collect($response->json('meta.warnings'))->pluck('related.id'));

        // Neighbours sharing an edge do not overlap.
        $this->owner()->postJson($this->url('/plots'), ['name' => 'P3', 'section_id' => $section['id'], 'boundary' => self::square(30.003, 0.0, 0.001)])
            ->assertCreated()->assertJsonPath('meta.warnings', []);
    }

    public function test_invalid_geometry_is_rejected(): void
    {
        $bowtie = ['type' => 'Polygon', 'coordinates' => [[[30, 0], [30.01, 0.01], [30.01, 0], [30, 0.01], [30, 0]]]];
        $open = ['type' => 'Polygon', 'coordinates' => [[[30, 0], [30.01, 0], [30.01, 0.01], [30, 0.01]]]];
        $point = ['type' => 'Point', 'coordinates' => [30, 0]];
        $outOfRange = self::square(200, 0, 1);

        foreach ([$bowtie, $open, $point, $outOfRange, 'not-geojson'] as $boundary) {
            $this->owner()->postJson($this->url('/blocks'), ['name' => 'Bad', 'boundary' => $boundary])
                ->assertStatus(422)->assertJsonValidationErrors('boundary');
        }
        $this->assertSame(0, $this->inFarm($this->farm, fn () => Block::withTrashed()->count()));
    }

    public function test_codes_are_unique_per_farm_even_after_archiving(): void
    {
        $id = $this->owner()->postJson($this->url('/blocks'), ['name' => 'A', 'code' => 'B-1'])->assertCreated()->json('data.id');
        $this->owner()->deleteJson($this->url("/blocks/{$id}"))->assertNoContent();

        $this->owner()->postJson($this->url('/blocks'), ['name' => 'Again', 'code' => 'b-1'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        // Another farm can use the same code.
        $other = $this->farm();
        $this->asUser($this->ownerOf($other))->postJson("/api/v1/farms/{$other->id}/structure/blocks", ['name' => 'A', 'code' => 'B-1'])->assertCreated();
    }

    public function test_update_uses_optimistic_version_and_audits_changes(): void
    {
        $block = $this->owner()->postJson($this->url('/blocks'), ['name' => 'Old'])->json('data');

        $this->owner()->patchJson($this->url("/blocks/{$block['id']}"), ['name' => 'New', 'version' => 1])
            ->assertOk()->assertJsonPath('data.name', 'New')->assertJsonPath('data.version', 2);

        $this->assertProblem(
            $this->owner()->withHeader('If-Match', '"1"')->patchJson($this->url("/blocks/{$block['id']}"), ['name' => 'Stale']),
            409, 'version_conflict',
        );
        $this->flushHeaders();

        $this->owner()->patchJson($this->url("/blocks/{$block['id']}"), ['boundary' => self::square(30, 0, 0.01)])
            ->assertOk()->assertJsonPath('data.version', 3);
        $this->owner()->patchJson($this->url("/blocks/{$block['id']}"), ['boundary' => null])
            ->assertOk()->assertJsonPath('data.boundary', null)->assertJsonPath('data.area_ha', null);

        $actions = $this->inFarm($this->farm, fn () => DB::table('audit_logs')->where('entity_id', $block['id'])->orderBy('created_at')->pluck('action')->all());
        $this->assertSame(['structure.block.created', 'structure.block.updated', 'structure.block.updated', 'structure.block.updated'], $actions);
    }

    public function test_archiving_requires_children_to_be_archived_first(): void
    {
        $block = $this->owner()->postJson($this->url('/blocks'), ['name' => 'B'])->json('data');
        $section = $this->owner()->postJson($this->url('/sections'), ['name' => 'S', 'block_id' => $block['id']])->json('data');

        $this->assertProblem($this->owner()->deleteJson($this->url("/blocks/{$block['id']}")), 409, 'has_active_children');

        $this->owner()->deleteJson($this->url("/sections/{$section['id']}"))->assertNoContent();
        $this->owner()->deleteJson($this->url("/blocks/{$block['id']}"))->assertNoContent();
        $this->owner()->getJson($this->url("/blocks/{$block['id']}"))->assertNotFound();
        $this->owner()->getJson($this->url())->assertJsonCount(0, 'data.blocks');
    }

    public function test_parents_must_belong_to_the_same_farm(): void
    {
        $other = $this->farm();
        $foreignBlock = $this->asUser($this->ownerOf($other))
            ->postJson("/api/v1/farms/{$other->id}/structure/blocks", ['name' => 'Theirs'])->json('data.id');

        $this->owner()->postJson($this->url('/sections'), ['name' => 'S', 'block_id' => $foreignBlock])
            ->assertStatus(422)->assertJsonValidationErrors('block_id');
        $this->owner()->postJson($this->url('/plots'), ['name' => 'P', 'section_id' => (string) Str::uuid7()])
            ->assertStatus(422)->assertJsonValidationErrors('section_id');
    }

    public function test_composite_foreign_keys_reject_cross_farm_plot_references(): void
    {
        $other = $this->farm();
        $foreignPlot = $this->asUser($this->ownerOf($other))
            ->postJson("/api/v1/farms/{$other->id}/structure/plots", ['name' => 'Theirs'])->json('data.id');

        $this->expectException(QueryException::class);
        $this->app->make(TenantContext::class)->bypass(fn () => DB::table('farm_locations')->insert([
            'id' => (string) Str::uuid7(), 'farm_id' => $this->farm->id, 'code' => 'X', 'name' => 'X', 'kind' => 'store',
            'plot_id' => $foreignPlot, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_trace_batches_cannot_point_at_another_farms_plot(): void
    {
        $other = $this->farm();
        $foreignPlot = $this->asUser($this->ownerOf($other))
            ->postJson("/api/v1/farms/{$other->id}/structure/plots", ['name' => 'Theirs'])->json('data.id');

        $this->expectException(QueryException::class);
        $this->app->make(TenantContext::class)->bypass(fn () => DB::table('trace_batches')->insert([
            'id' => (string) Str::uuid7(), 'farm_id' => $this->farm->id, 'batch_code' => 'T-X1', 'kind' => 'crop_lot',
            'status' => 'open', 'origin_plot_id' => $foreignPlot, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_role_permissions_on_structure(): void
    {
        $plot = $this->owner()->postJson($this->url('/plots'), ['name' => 'P'])->json('data.id');

        $agronomist = $this->memberWithRole($this->farm, 'agronomist');
        $this->asUser($agronomist)->getJson($this->url())->assertOk()->assertJsonCount(1, 'data.plots');
        $this->assertProblem($this->asUser($agronomist)->postJson($this->url('/plots'), ['name' => 'X']), 403, 'forbidden');
        $this->asUser($agronomist)->putJson($this->url("/plots/{$plot}/soil"), ['texture' => 'clay_loam', 'ph' => 6.2, 'tested_on' => now()->toDateString()])
            ->assertOk()->assertJsonPath('data.soil_profile.texture', 'clay_loam')->assertJsonPath('data.soil_profile.ph', 6.2);

        $store = $this->memberWithRole($this->farm, 'store_manager');
        $this->assertProblem($this->asUser($store)->putJson($this->url("/plots/{$plot}/soil"), ['ph' => 5]), 403, 'forbidden');

        $manager = $this->memberWithRole($this->farm, 'manager');
        $this->asUser($manager)->patchJson($this->url("/plots/{$plot}"), ['land_use' => 'pasture'])->assertOk()->assertJsonPath('data.land_use', 'pasture');

        // Field workers see only plots they are assigned to (tasks arrive in Phase 6).
        $worker = $this->memberWithRole($this->farm, 'field_worker');
        $this->asUser($worker)->getJson($this->url())->assertOk()->assertJsonCount(0, 'data.plots');
        $this->asUser($worker)->getJson($this->url("/plots/{$plot}"))->assertNotFound();

        $accountant = $this->memberWithRole($this->farm, 'accountant');
        $this->assertProblem($this->asUser($accountant)->deleteJson($this->url("/plots/{$plot}")), 403, 'forbidden');
    }

    public function test_rows_are_invisible_without_tenant_context_under_rls(): void
    {
        if (! $this->isPgsql()) {
            $this->markTestSkipped('Row-level security is PostgreSQL-only.');
        }

        $this->owner()->postJson($this->url('/plots'), ['name' => 'P'])->assertCreated();

        $this->assertSame(1, $this->inFarm($this->farm, fn () => Plot::count()));
        $other = $this->farm();
        $this->assertSame(0, $this->inFarm($other, fn () => DB::table('farm_plots')->count()));
    }
}
