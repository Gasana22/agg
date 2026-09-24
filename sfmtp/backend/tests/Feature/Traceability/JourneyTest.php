<?php

namespace Tests\Feature\Traceability;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Models\TraceEvent;
use App\Modules\Traceability\Notifications\TraceChainBroken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 9 gate (docs/10): a seed → customer journey is reconstructed from
 * normal work, corrections append and show, and the tamper check catches
 * altered data.
 */
class JourneyTest extends TestCase
{
    private Farm $farm;

    private User $owner;

    private User $agronomist;

    private User $store;

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->farm = $this->farm();
        $this->owner = $this->ownerOf($this->farm);
        $this->agronomist = $this->memberWithRole($this->farm, 'agronomist');
        $this->store = $this->memberWithRole($this->farm, 'store_manager');
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}{$path}";
    }

    private function trace(string $path): string
    {
        return $this->url("/traceability{$path}");
    }

    /**
     * Maize on plot B-3 from a seed lot, sprayed, harvested (1,020 kg),
     * 520 kg split off, dried to 500 kg, packed in 50 kg bags, and 300 kg
     * shipped to a customer who confirms delivery.
     */
    private function scenario(): void
    {
        $plot = $this->asUser($this->owner)->postJson($this->url('/structure/plots'), ['name' => 'B-3', 'code' => 'B-3', 'boundary' => [
            'type' => 'Polygon', 'coordinates' => [[[32.58, 0.34], [32.582, 0.34], [32.582, 0.342], [32.58, 0.342], [32.58, 0.34]]],
        ]])->assertCreated()->json('data.id');
        $crop = $this->asUser($this->agronomist)->postJson($this->url('/crops'), ['global_variety_id' => DB::table('global_crop_varieties')->where('code', 'longe_5')->value('id')])
            ->assertCreated()->json('data.id');
        $seed = $this->asUser($this->agronomist)->postJson($this->trace('/batches'), ['kind' => 'seed_lot', 'name' => 'Longe 5 seed SC-2291', 'quantity' => 25, 'unit' => 'kg'])
            ->assertCreated()->json('data.id');
        $cycle = $this->asUser($this->agronomist)->postJson($this->url('/crop-cycles'), [
            'plot_id' => $plot, 'crop_id' => $crop, 'seed_batch_id' => $seed, 'planted_on' => now()->subDays(130)->toDateString(),
        ])->assertCreated()->json('data');
        $this->asUser($this->agronomist)->postJson($this->url('/crop-operations'), [
            'cycle_id' => $cycle['id'], 'type' => 'spraying', 'occurred_at' => now()->subDays(60)->toIso8601String(), 'latitude' => 0.341, 'longitude' => 32.581,
            'inputs' => [['product_name' => 'Emamectin benzoate 5% SG', 'quantity' => 0.4, 'unit' => 'kg', 'withholding_days' => 14]],
        ])->assertCreated();
        $harvest = $this->asUser($this->agronomist)->postJson($this->url('/harvests'), ['cycle_id' => $cycle['id'], 'harvested_on' => now()->subDays(10)->toDateString(), 'quantity' => 1020, 'unit' => 'kg'])
            ->assertCreated()->json('data.batch.id');

        $split = $this->asUser($this->store)->postJson($this->trace("/batches/{$harvest}/split"), ['parts' => [['quantity' => 520, 'name' => 'Maize for drying']]])
            ->assertCreated()
            ->assertJsonPath('data.source.available.value', '500.000')
            ->assertJsonPath('data.source.status', 'open')
            ->assertJsonPath('data.parts.0.quantity.value', '520.000')
            ->assertJsonPath('data.parts.0.kind', 'harvest')
            ->json('data.parts.0.id');
        $dried = $this->asUser($this->store)->postJson($this->trace("/batches/{$split}/process"), ['output' => ['name' => 'Dried maize grain', 'quantity' => 500, 'unit' => 'kg', 'method' => 'Sun drying to 13%']])
            ->assertCreated()->assertJsonPath('data.kind', 'processed')->assertJsonPath('data.quantity.value', '500.000')->json('data.id');
        $this->asUser($this->store)->getJson($this->trace("/batches/{$split}"))->assertJsonPath('data.status', 'closed')->assertJsonPath('data.available.value', '0.000');
        $packed = $this->asUser($this->store)->postJson($this->trace("/batches/{$dried}/package"), ['output' => ['name' => 'Maize grain 50 kg bags', 'package_count' => 10, 'package_size' => '50 kg']])
            ->assertCreated()->assertJsonPath('data.kind', 'packaged')->assertJsonPath('data.quantity.value', '500.000')->assertJsonPath('data.quantity.unit', 'kg')->json('data.id');

        $accountant = $this->memberWithRole($this->farm, 'accountant');
        $customer = $this->asUser($accountant)->postJson($this->url('/customers'), ['name' => 'Kampala Millers', 'address' => 'Plot 4, Industrial Area'])->assertCreated()->json('data.id');
        $shipment = $this->asUser($this->store)->postJson($this->url('/shipments'), [
            'customer_id' => $customer, 'vehicle' => 'UBA 123X', 'lines' => [['batch_id' => $packed, 'quantity' => 300]],
            'dispatched_at' => now()->subDays(2)->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('data.code', 'SHP-001')
            ->assertJsonPath('data.status', 'dispatched')
            ->assertJsonPath('data.destination', 'Plot 4, Industrial Area')
            ->assertJsonPath('data.lines.0.quantity.value', '300.000')
            ->json('data');
        $this->asUser($this->store)->postJson($this->url("/shipments/{$shipment['id']}/deliver"), ['received_by' => 'J. Okot', 'delivered_at' => now()->subDay()->toIso8601String()])
            ->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.trace_batch.status', 'closed');

        $this->ids = compact('plot', 'seed', 'harvest', 'split', 'dried', 'packed', 'customer') + ['cycle' => $cycle['id'], 'crop_lot' => $cycle['crop_lot']['id'],
            'shipment' => $shipment['id'], 'shipment_batch' => $shipment['trace_batch']['id']];
    }

    public function test_the_seed_to_customer_journey_is_reconstructed(): void
    {
        $this->scenario();
        $ids = $this->ids;

        // Backward from what the customer received, all the way to the seed.
        $back = $this->asUser($this->owner)->getJson($this->trace("/batches/{$ids['shipment_batch']}/journey?direction=backward"))->assertOk()->json('data.backward');
        $kinds = collect($back['nodes'])->pluck('kind', 'id');
        foreach (['seed' => 'seed_lot', 'crop_lot' => 'crop_lot', 'harvest' => 'harvest', 'split' => 'harvest', 'dried' => 'processed', 'packed' => 'packaged'] as $key => $kind) {
            $this->assertSame($kind, $kinds[$ids[$key]] ?? null, "{$key} is upstream of the shipment");
        }
        $this->assertEqualsCanonicalizing(['derived', 'derived', 'split', 'process', 'package', 'ship'], array_column($back['edges'], 'link_type'));

        // Forward from the seed to the customer.
        $forward = $this->asUser($this->owner)->getJson($this->trace("/batches/{$ids['seed']}/journey"))->assertOk()
            ->assertJsonPath('data.projection.source', 'projection')
            ->json('data');
        $this->assertContains($ids['shipment_batch'], array_column($forward['forward']['nodes'], 'id'));
        $this->assertSame(6, $forward['evidence_summary']['destinations']);

        $sales = $this->asUser($this->owner)->getJson($this->trace("/batches/{$ids['seed']}/sales"))->assertOk()->json('data');
        $this->assertCount(1, $sales);
        $this->assertSame('Kampala Millers', $sales[0]['customer']);
        $this->assertSame('SHP-001', $sales[0]['shipment']['code']);
        $this->assertSame('J. Okot', $sales[0]['received_by']);
        $this->assertNotNull($sales[0]['delivered_at']);
        $this->assertSame([['batch_id' => $ids['packed'], 'batch_code' => $sales[0]['from'][0]['batch_code'], 'quantity' => '300.000', 'unit' => 'kg']], $sales[0]['from']);
        $this->assertArrayNotHasKey('amount', $sales[0]);

        $inputs = $this->asUser($this->owner)->getJson($this->trace("/batches/{$ids['shipment_batch']}/inputs"))->assertOk()->json('data');
        $this->assertSame([$ids['seed']], array_column(array_column($inputs['lots'], 'batch'), 'id'));
        $this->assertSame('source', $inputs['lots'][0]['role']);
        $this->assertSame('Emamectin benzoate 5% SG', $inputs['applications'][0]['product']);
        $this->assertSame(14, $inputs['applications'][0]['withholding_days']);

        $places = $this->asUser($this->owner)->getJson($this->trace("/batches/{$ids['packed']}/locations"))->assertOk()->json('data');
        $this->assertSame(['B-3'], array_column($places['plots'], 'code'));
        $this->assertNotNull($places['plots'][0]['centroid']);
        $this->assertSame('operation', $places['points'][0]['event_type']);

        $who = $this->asUser($this->owner)->getJson($this->trace("/batches/{$ids['packed']}/workers"))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$this->agronomist->id, $this->store->id], array_column($who['recorders'], 'user_id'));

        // The timeline reads from sowing to delivery.
        $timeline = $this->asUser($this->owner)->getJson($this->trace("/batches/{$ids['shipment_batch']}/timeline"))->assertOk()->json('data');
        $types = array_column($timeline, 'event_type');
        $this->assertSame('created', $types[0]);
        $this->assertContains($ids['seed'], array_column(array_column($timeline, 'batch'), 'id'));
        foreach (['planted', 'operation', 'input_applied', 'harvested', 'dispatched', 'delivered'] as $type) {
            $this->assertContains($type, $types);
        }
        $this->assertLessThan(array_search('delivered', $types, true), array_search('planted', $types, true));
        $times = array_column($timeline, 'occurred_at');
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times);

        // The shipment appears in Sales, and a recall reaches it.
        $this->asUser($this->owner)->getJson($this->url('/shipments'))->assertOk()->assertJsonPath('data.0.customer.name', 'Kampala Millers');
    }

    public function test_quantities_cannot_be_used_twice(): void
    {
        $this->scenario();
        $ids = $this->ids;

        $tooMuch = $this->asUser($this->store)->postJson($this->trace("/batches/{$ids['harvest']}/split"), ['parts' => [['quantity' => 300], ['quantity' => 300]]]);
        $this->assertProblem($tooMuch, 422, 'trace_quantity_exceeded');
        $tooMuch->assertJsonPath('available', '500.000');
        $this->assertProblem($this->asUser($this->store)->postJson($this->trace("/batches/{$ids['split']}/process"), ['output' => ['name' => 'Again']]), 409, 'trace_batch_not_open');
        $this->assertProblem($this->asUser($this->store)->postJson($this->url('/shipments'), ['customer_id' => $ids['customer'], 'lines' => [['batch_id' => $ids['packed'], 'quantity' => 201]]]), 422, 'trace_quantity_exceeded');
        $this->assertProblem($this->asUser($this->owner)->postJson($this->trace("/batches/{$ids['packed']}/links"), ['parent_batch_id' => $ids['harvest'], 'link_type' => 'process', 'quantity' => 501, 'unit' => 'kg']), 422, 'trace_quantity_exceeded');
        $this->assertProblem($this->asUser($this->owner)->postJson($this->trace("/batches/{$ids['shipment_batch']}/split"), ['parts' => [['quantity' => 1]]]), 409, 'trace_batch_not_open');

        // Merging the rest of the harvest with another plot's harvest.
        $other = $this->inFarm($this->farm, fn () => app(Recorder::class)
            ->createBatch(BatchKind::Harvest, ['name' => 'Maize A-1', 'quantity' => '200', 'unit' => 'kg']));
        $this->assertProblem($this->asUser($this->store)->postJson($this->trace("/batches/{$ids['harvest']}/merge"), ['with' => [['batch_id' => $ids['packed']]]]), 422, 'trace_merge_mixed');
        $merged = $this->asUser($this->store)->postJson($this->trace("/batches/{$ids['harvest']}/merge"), ['name' => 'Maize store bin 2', 'quantity' => 400, 'with' => [['batch_id' => $other->id]]])
            ->assertCreated()->assertJsonPath('data.kind', 'harvest')->assertJsonPath('data.quantity.value', '600.000')->assertJsonPath('data.origin_plot_id', null)->json('data.id');
        $this->asUser($this->store)->getJson($this->trace("/batches/{$ids['harvest']}"))->assertJsonPath('data.available.value', '100.000')->assertJsonPath('data.status', 'open');
        $this->asUser($this->store)->getJson($this->trace("/batches/{$other->id}"))->assertJsonPath('data.status', 'closed');
        $back = $this->asUser($this->store)->getJson($this->trace("/batches/{$merged}/journey?direction=backward"))->json('data.backward.nodes');
        $this->assertContains($ids['seed'], array_column($back, 'id'));

        // Two stores taking the last 100 kg at once: one wins.
        $this->asUser($this->store)->postJson($this->trace("/batches/{$ids['harvest']}/split"), ['parts' => [['quantity' => 100]]])->assertCreated();
        $this->assertProblem($this->asUser($this->store)->postJson($this->trace("/batches/{$ids['harvest']}/split"), ['parts' => [['quantity' => 1]]]), 409, 'trace_batch_not_open');
    }

    public function test_a_recall_follows_the_product_to_the_customer(): void
    {
        $this->scenario();
        $ids = $this->ids;

        // Only people who publish may recall.
        $this->asUser($this->store)->postJson($this->trace("/batches/{$ids['harvest']}/recall"), ['reason' => 'Aflatoxin above limit'])->assertForbidden();
        $this->assertProblem($this->asUser($this->store)->postJson($this->trace("/batches/{$ids['harvest']}/status"), ['status' => 'recalled', 'reason' => 'Aflatoxin']), 403, 'forbidden');

        $result = $this->asUser($this->agronomist)->postJson($this->trace("/batches/{$ids['harvest']}/recall"), ['reason' => 'Aflatoxin above limit'])
            ->assertOk()->assertJsonPath('data.batch.status', 'recalled')->assertJsonPath('data.shipments', 1)->json('data');
        $this->assertEqualsCanonicalizing([$ids['harvest'], $ids['split'], $ids['dried'], $ids['packed'], $ids['shipment_batch']], array_column($result['affected'], 'id'));
        // Upstream is not recalled.
        $this->asUser($this->owner)->getJson($this->trace("/batches/{$ids['crop_lot']}"))->assertJsonPath('data.status', 'open');
        $payload = $this->inFarm($this->farm, fn () => TraceEvent::where('batch_id', $ids['shipment_batch'])->where('event_type', 'status_changed')->latest('farm_seq')->first()->payload);
        $this->assertSame('recalled', $payload['to']);
        $this->assertNotEmpty($payload['recall_of']);

        $alerts = collect($this->asUser($this->owner)->getJson($this->trace('/alerts'))->assertOk()->json('data'))->keyBy('code');
        $this->assertSame('critical', $alerts['recalled_shipped']['severity']);
        $this->assertSame('Kampala Millers', $alerts['recalled_shipped']['items'][0]['customer']);

        $this->assertProblem($this->asUser($this->agronomist)->postJson($this->trace("/batches/{$ids['packed']}/recall"), ['reason' => 'again']), 409, 'invalid_state_transition');
        $this->assertProblem($this->asUser($this->store)->postJson($this->trace("/batches/{$ids['packed']}/status"), ['status' => 'open', 'reason' => 'fine']), 409, 'invalid_state_transition');
    }

    public function test_corrections_show_in_the_journey_and_keep_the_original(): void
    {
        $this->scenario();
        $ids = $this->ids;
        $harvested = $this->inFarm($this->farm, fn () => TraceEvent::where('batch_id', $ids['harvest'])->where('event_type', 'harvested')->firstOrFail());

        $this->asUser($this->agronomist)->postJson($this->trace("/events/{$harvested->id}/corrections"), ['payload' => ['quantity' => '1010.000'], 'reason' => 'Scale was off by 10 kg'])
            ->assertCreated()->assertJsonPath('data.event_type', 'correction');

        $timeline = collect($this->asUser($this->owner)->getJson($this->trace("/batches/{$ids['packed']}/timeline"))->json('data'));
        $event = $timeline->firstWhere('id', $harvested->id);
        $this->assertTrue($event['corrected']);
        $this->assertSame('1010.000', $event['payload']['quantity']);
        $this->assertSame('1020', $event['original_payload']['quantity']);
        $this->assertSame('Scale was off by 10 kg', $event['corrections'][0]['reason']);
        $this->assertNull($timeline->firstWhere('event_type', 'correction'), 'corrections are folded into the event they correct');

        // The original row is untouched and the chain still verifies.
        $this->assertSame('1020', $this->inFarm($this->farm, fn () => TraceEvent::findOrFail($harvested->id))->payload['quantity']);
        $this->asUser($this->owner)->postJson($this->trace('/integrity/verify'))->assertCreated()->assertJsonPath('data.result', 'pass');

        // The projection picked the correction up.
        $summary = $this->asUser($this->owner)->getJson($this->trace("/batches/{$ids['packed']}/journey"))->assertJsonPath('data.projection.source', 'projection')->json('data.evidence_summary');
        $this->assertSame(1, $summary['corrections']);
    }

    public function test_the_tamper_check_catches_altered_data_and_raises_an_alert(): void
    {
        if (! $this->isPgsql()) {
            $this->markTestSkipped('Needs transactional DDL to switch the append-only trigger off inside the test.');
        }
        $this->scenario();
        Notification::fake();

        $this->asUser($this->owner)->postJson($this->trace('/integrity/verify'))->assertCreated()->assertJsonPath('data.result', 'pass');
        $this->asUser($this->owner)->getJson($this->trace('/alerts'))->assertOk()->assertJsonPath('meta.counts.critical', 0);

        // Someone with database access changes the harvested quantity.
        DB::statement('ALTER TABLE trace_events DISABLE TRIGGER trace_events_append_only');
        $seq = $this->inFarm($this->farm, function () {
            $row = DB::table('trace_events')->where('event_type', 'harvested')->first();
            DB::table('trace_events')->where('id', $row->id)->update(['payload' => json_encode(['quantity' => '2040.000', 'unit' => 'kg'])]);

            return (int) $row->farm_seq;
        });
        DB::statement('ALTER TABLE trace_events ENABLE TRIGGER trace_events_append_only');

        $this->asUser($this->owner)->postJson($this->trace('/integrity/verify'))->assertCreated()
            ->assertJsonPath('data.result', 'fail')->assertJsonPath('data.reason', 'hash_mismatch')->assertJsonPath('data.first_bad_seq', $seq);
        $this->asUser($this->owner)->getJson($this->trace('/integrity'))->assertOk()->assertJsonPath('data.latest.result', 'fail')->assertJsonPath('data.history.1.result', 'pass');
        $alerts = $this->asUser($this->owner)->getJson($this->trace('/alerts'))->assertOk()->assertJsonPath('data.0.code', 'chain_failed')->json();
        $this->assertGreaterThanOrEqual(1, $alerts['meta']['counts']['critical']);

        // The nightly job fails and tells the owner.
        $this->artisan('trace:verify-chain', ['--farm' => $this->farm->id])->assertFailed();
        Notification::assertSentTo($this->owner, TraceChainBroken::class);

        // Field workers cannot run checks; the store cannot either.
        $this->asUser($this->store)->postJson($this->trace('/integrity/verify'))->assertForbidden();
    }
}
