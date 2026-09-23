<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Access\Domain\Models\FarmInvitation;
use App\Modules\Access\Domain\Models\FarmRole;
use App\Modules\FarmStructure\Application\StructureService;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Models\TraceEvent;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Release gate (docs/02-tenant-isolation.md §7): visits EVERY route under
 * /farms/{farm} as the owner of another farm. New routes are covered
 * automatically; a route with a new parameter fails until it is mapped here.
 */
class CrossTenantIsolationTest extends TestCase
{
    private Farm $victim;

    private Farm $attackerFarm;

    /** @var array<string,string> route parameter => a record id belonging to the victim farm */
    private array $victimRecords;

    protected function setUp(): void
    {
        parent::setUp();

        $this->victim = $this->farm();
        $this->attackerFarm = $this->farm();

        $recorder = $this->app->make(Recorder::class);
        $structure = $this->app->make(StructureService::class);
        $victimMember = $this->memberWithRole($this->victim, 'agronomist');
        $this->victimRecords = $this->inFarm($this->victim, function () use ($recorder, $structure, $victimMember) {
            $batch = $recorder->createBatch(BatchKind::SeedLot, ['name' => 'Victim seed']);
            [$block] = $structure->create('block', ['name' => 'Victim block']);
            [$section] = $structure->create('section', ['name' => 'Victim section', 'block_id' => $block->id]);
            [$plot] = $structure->create('plot', ['name' => 'Victim plot', 'section_id' => $section->id]);
            [$location] = $structure->create('location', ['name' => 'Victim store', 'kind' => 'store', 'plot_id' => $plot->id]);

            return [
                'block' => $block->id,
                'section' => $section->id,
                'plot' => $plot->id,
                'location' => $location->id,
                'member' => FarmUser::where('farm_id', $this->victim->id)->where('user_id', $victimMember->id)->value('id'),
                'invitation' => FarmInvitation::create([
                    'email' => 'victim-invitee@example.com', 'token_hash' => str_repeat('a', 64), 'invited_by' => $this->ownerOf($this->victim)->id,
                    'expires_at' => now()->addDay(), 'last_sent_at' => now(),
                ])->id,
                'batch' => $batch->id,
                'event' => TraceEvent::where('batch_id', $batch->id)->value('id'),
                'role' => FarmRole::where('key', 'manager')->value('id'),
                'dashboard' => 'owner',
                'widget' => 'trace_activity',
            ];
        });
    }

    /** @return array<int,Route> */
    private function farmRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $r) => str_starts_with($r->uri(), 'api/v1/farms/{farm}'),
        ));
    }

    private function url(Route $route, string $farmId): string
    {
        $url = str_replace('{farm}', $farmId, $route->uri());

        foreach ($route->parameterNames() as $name) {
            if ($name === 'farm') {
                continue;
            }
            $this->assertArrayHasKey($name, $this->victimRecords, "Route {$route->uri()} has an unmapped parameter {{$name}}: add it to CrossTenantIsolationTest.");
            $url = str_replace('{'.$name.'}', $this->victimRecords[$name], $url);
        }

        return '/'.$url;
    }

    private function snapshot(): array
    {
        return DB::transaction(fn () => $this->app->make(TenantContext::class)->bypass(fn () => [
            'batches' => DB::table('trace_batches')->count(),
            'events' => DB::table('trace_events')->count(),
            'links' => DB::table('trace_batch_links')->count(),
            'farms' => DB::table('farms')->where('status', 'active')->count(),
            'grants' => DB::table('farm_role_permissions')->count(),
            'structure' => DB::table('farm_blocks')->whereNull('deleted_at')->count() + DB::table('farm_sections')->whereNull('deleted_at')->count()
                + DB::table('farm_plots')->whereNull('deleted_at')->count() + DB::table('farm_locations')->whereNull('deleted_at')->count(),
            'structure_versions' => DB::table('farm_plots')->sum('version'),
            'members' => DB::table('farm_users')->where('status', 'active')->count(),
            'member_roles' => DB::table('farm_user_roles')->count(),
            'invitations' => DB::table('farm_invitations')->whereNull('revoked_at')->count(),
            'roles' => DB::table('farm_roles')->count(),
            'settings' => DB::table('farm_settings')->orderBy('farm_id')->pluck('settings')->all(),
        ]));
    }

    public function test_every_farm_route_hides_another_farm_behind_404(): void
    {
        $attacker = $this->ownerOf($this->attackerFarm);
        $before = $this->snapshot();
        $routes = $this->farmRoutes();
        $this->assertGreaterThan(15, count($routes));

        foreach ($routes as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->asUser($attacker)->json($method, $this->url($route, $this->victim->id), ['name' => 'x', 'grants' => []]);

                $this->assertSame(404, $response->status(), "{$method} {$route->uri()} leaked with status {$response->status()}");
            }
        }

        $this->assertSame($before, $this->snapshot(), 'A cross-tenant request changed data.');
    }

    public function test_own_farm_path_with_another_farms_record_ids_is_not_found(): void
    {
        $attacker = $this->ownerOf($this->attackerFarm);
        $before = $this->snapshot();

        foreach ($this->farmRoutes() as $route) {
            $names = array_diff($route->parameterNames(), ['farm', 'dashboard', 'widget']);
            if ($names === []) {
                continue;   // no record ids in the path
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->asUser($attacker)->json($method, $this->url($route, $this->attackerFarm->id), ['grants' => [], 'reason' => 'x', 'payload' => ['a' => 1], 'status' => 'closed', 'event_type' => 'note']);

                $this->assertSame(404, $response->status(), "{$method} {$route->uri()} resolved another farm's record (status {$response->status()})");
            }
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_request_bodies_cannot_reference_another_farms_records(): void
    {
        $attacker = $this->ownerOf($this->attackerFarm);
        $own = $this->inFarm($this->attackerFarm, fn () => $this->app->make(Recorder::class)->createBatch(BatchKind::Processed));

        $this->asUser($attacker)->postJson(
            "/api/v1/farms/{$this->attackerFarm->id}/traceability/batches/{$own->id}/links",
            ['parent_batch_id' => $this->victimRecords['batch'], 'link_type' => 'derived'],
        )->assertStatus(422)->assertJsonValidationErrors('parent_batch_id');
    }

    public function test_random_farm_ids_are_indistinguishable_from_forbidden_ones(): void
    {
        $attacker = $this->ownerOf($this->attackerFarm);

        $this->assertProblem($this->asUser($attacker)->getJson('/api/v1/farms/'.Str::uuid7()), 404, 'not_found');
        $this->assertProblem($this->asUser($attacker)->getJson('/api/v1/farms/not-a-uuid'), 404, 'not_found');
        $this->assertProblem($this->asUser($attacker)->getJson("/api/v1/farms/{$this->victim->id}"), 404, 'not_found');
    }
}
