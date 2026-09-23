<?php

namespace Tests\Feature\Traceability;

use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Traceability\Application\ChainVerifier;
use App\Modules\Traceability\Application\EventHasher;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Enums\LinkType;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Domain\Models\TraceEvent;
use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TraceabilityTest extends TestCase
{
    private Farm $farm;

    private Recorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->farm = $this->farm();
        $this->recorder = $this->app->make(Recorder::class);
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}/traceability{$path}";
    }

    private function owner()
    {
        return $this->asUser($this->ownerOf($this->farm));
    }

    /** seed → crop lot → harvest → (split) processed → packaged */
    private function chain(): array
    {
        return $this->inFarm($this->farm, function () {
            $r = $this->recorder;
            $seed = $r->createBatch(BatchKind::SeedLot, ['name' => 'Seed']);
            $lot = $r->createBatch(BatchKind::CropLot, ['name' => 'Lot']);
            $harvest = $r->createBatch(BatchKind::Harvest, ['quantity' => '1020', 'unit' => 'kg']);
            $processed = $r->createBatch(BatchKind::Processed, ['quantity' => '520', 'unit' => 'kg']);
            $packaged = $r->createBatch(BatchKind::Packaged, ['quantity' => '500', 'unit' => 'kg']);
            $r->link($seed, $lot, LinkType::Derived);
            $r->link($lot, $harvest, LinkType::Derived);
            $r->link($harvest, $processed, LinkType::Split, '520', 'kg');
            $r->link($processed, $packaged, LinkType::Package, '500', 'kg');

            return compact('seed', 'lot', 'harvest', 'processed', 'packaged');
        });
    }

    public function test_creating_a_batch_records_a_created_event(): void
    {
        $response = $this->owner()->postJson($this->url('/batches'), [
            'kind' => 'packaged', 'name' => 'Maize 50 kg', 'quantity' => 500, 'unit' => 'kg', 'notes' => 'Line 2',
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'packaged')
            ->assertJsonPath('data.quantity.value', '500.000')
            ->assertJsonPath('data.status', 'open');

        $this->assertMatchesRegularExpression('/^SFM-[0-9A-Z]{4}-[0-9A-Z]{4}$/', $response->json('data.batch_code'));

        $events = $this->owner()->getJson($this->url('/batches/'.$response->json('data.id').'/events'))->assertOk()->json('data');
        $this->assertCount(1, $events);
        $this->assertSame('created', $events[0]['event_type']);
        $this->assertSame('Line 2', $events[0]['payload']['notes']);
        $this->assertSame(1, $events[0]['integrity']['seq']);
        $this->assertSame(EventHasher::GENESIS, $events[0]['integrity']['prev_hash']);
    }

    public function test_domain_only_kinds_cannot_be_created_by_hand(): void
    {
        $this->owner()->postJson($this->url('/batches'), ['kind' => 'harvest'])->assertUnprocessable()->assertJsonValidationErrors('kind');
    }

    public function test_journey_answers_where_it_came_from_and_where_it_went(): void
    {
        ['seed' => $seed, 'harvest' => $harvest, 'packaged' => $packaged] = $this->chain();

        $back = $this->owner()->getJson($this->url("/batches/{$packaged->id}/journey?direction=backward"))->assertOk()->json('data');
        $this->assertNull($back['forward']);
        $this->assertEqualsCanonicalizing(['processed', 'harvest', 'crop_lot', 'seed_lot'], array_column($back['backward']['nodes'], 'kind'));
        $this->assertCount(4, $back['backward']['edges']);
        $this->assertSame(4, collect($back['backward']['nodes'])->firstWhere('id', $seed->id)['depth']);

        $forward = $this->owner()->getJson($this->url("/batches/{$harvest->id}/journey?direction=forward"))->json('data.forward');
        $this->assertEqualsCanonicalizing(['processed', 'packaged'], array_column($forward['nodes'], 'kind'));

        $shallow = $this->owner()->getJson($this->url("/batches/{$packaged->id}/journey?direction=backward&depth=1"))->json('data.backward');
        $this->assertSame(['processed'], array_column($shallow['nodes'], 'kind'));
    }

    public function test_links_can_be_added_over_the_api_but_never_make_a_cycle(): void
    {
        ['seed' => $seed, 'lot' => $lot, 'packaged' => $packaged] = $this->chain();

        $this->assertProblem(
            $this->owner()->postJson($this->url("/batches/{$seed->id}/links"), ['parent_batch_id' => $packaged->id, 'link_type' => 'derived']),
            422, 'trace_link_cycle',
        );
        $this->assertProblem(
            $this->owner()->postJson($this->url("/batches/{$seed->id}/links"), ['parent_batch_id' => $seed->id, 'link_type' => 'derived']),
            422, 'trace_link_self',
        );
        $this->assertProblem(
            $this->owner()->postJson($this->url("/batches/{$lot->id}/links"), ['parent_batch_id' => $seed->id, 'link_type' => 'derived']),
            409, 'duplicate',
        );

        $extra = $this->inFarm($this->farm, fn () => $this->recorder->createBatch(BatchKind::InputLot));
        $this->owner()->postJson($this->url("/batches/{$lot->id}/links"), ['parent_batch_id' => $extra->id, 'link_type' => 'merge'])
            ->assertCreated()->assertJsonPath('data.from', $extra->id);
    }

    public function test_manual_events_keep_device_time_and_location(): void
    {
        $batch = $this->inFarm($this->farm, fn () => $this->recorder->createBatch(BatchKind::CropLot));
        $when = now()->subDays(2)->startOfSecond();

        $this->owner()->postJson($this->url("/batches/{$batch->id}/events"), [
            'event_type' => 'inspection',
            'occurred_at' => $when->toIso8601String(),
            'payload' => ['note' => 'Leaf rust on 5% of plants', 'severity' => 'low'],
            'location' => ['lat' => 0.37361234, 'lng' => 32.7123, 'accuracy_m' => 6.5],
        ])->assertCreated()
            ->assertJsonPath('data.recorded_late', true)
            ->assertJsonPath('data.location.lat', 0.3736123)
            ->assertJsonPath('data.occurred_at', $when->utc()->format('Y-m-d\TH:i:s.u\Z'));

        $this->owner()->postJson($this->url("/batches/{$batch->id}/events"), ['event_type' => 'harvested'])->assertUnprocessable();
        $this->owner()->postJson($this->url("/batches/{$batch->id}/events"), ['event_type' => 'note', 'occurred_at' => now()->addHour()->toIso8601String()])->assertUnprocessable();
    }

    public function test_corrections_append_instead_of_editing(): void
    {
        $batch = $this->inFarm($this->farm, fn () => $this->recorder->createBatch(BatchKind::CropLot));
        $original = $this->inFarm($this->farm, fn () => $this->recorder->record($batch, 'inspection', ['payload' => ['plants' => 120]]));

        $correction = $this->owner()->postJson($this->url("/events/{$original->id}/corrections"), [
            'payload' => ['plants' => 210], 'reason' => 'Digits swapped',
        ])->assertCreated()->json('data');

        $this->assertSame('correction', $correction['event_type']);
        $this->assertSame($original->id, $correction['corrects_event_id']);
        $this->assertSame(['plants' => 210], $correction['payload']['corrected']);
        $this->assertSame(['plants' => 120], $this->inFarm($this->farm, fn () => TraceEvent::find($original->id))->payload);
    }

    public function test_history_cannot_be_edited_or_deleted(): void
    {
        $batch = $this->inFarm($this->farm, fn () => $this->recorder->createBatch(BatchKind::SeedLot));

        $this->assertProblem($this->owner()->deleteJson($this->url("/batches/{$batch->id}")), 405, 'append_only');

        $event = $this->inFarm($this->farm, fn () => TraceEvent::first());
        $this->expectException(AppendOnlyViolation::class);
        $this->inFarm($this->farm, fn () => $event->update(['event_type' => 'tampered']));
    }

    public function test_the_database_itself_rejects_edits_to_events(): void
    {
        $this->inFarm($this->farm, fn () => $this->recorder->createBatch(BatchKind::SeedLot));

        try {
            $this->inFarm($this->farm, fn () => DB::table('trace_events')->update(['event_type' => 'tampered']));
            $this->fail('UPDATE on trace_events was allowed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString(AppendOnlyViolation::MARKER, $e->getMessage());
        }
    }

    public function test_status_changes_are_recorded_as_events(): void
    {
        $batch = $this->inFarm($this->farm, fn () => $this->recorder->createBatch(BatchKind::Packaged));

        $this->owner()->postJson($this->url("/batches/{$batch->id}/status"), ['status' => 'recalled', 'reason' => 'Aflatoxin test failed'])
            ->assertOk()->assertJsonPath('data.status', 'recalled')->assertJsonPath('data.version', 2);
        $this->assertProblem($this->owner()->postJson($this->url("/batches/{$batch->id}/status"), ['status' => 'recalled', 'reason' => 'again']), 409, 'invalid_state_transition');

        $types = array_column($this->owner()->getJson($this->url("/batches/{$batch->id}/events"))->json('data'), 'event_type');
        $this->assertSame(['created', 'status_changed'], $types);
    }

    public function test_listing_filters_and_searches(): void
    {
        $this->chain();

        $this->owner()->getJson($this->url('/batches?filter[kind]=harvest'))->assertOk()->assertJsonCount(1, 'data');
        $this->owner()->getJson($this->url('/batches?q=seed'))->assertOk()->assertJsonCount(1, 'data');
        $this->owner()->getJson($this->url('/batches?per_page=2'))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.per_page', 2);
        $this->owner()->getJson($this->url('/batches?filter[kind]=spaceship'))->assertUnprocessable();
    }

    public function test_the_hash_chain_verifies_and_is_per_farm(): void
    {
        $this->chain();
        $other = $this->farm();
        $this->inFarm($other, fn () => $this->recorder->createBatch(BatchKind::SeedLot));

        $result = $this->inFarm($this->farm, fn () => $this->app->make(ChainVerifier::class)->verify());
        $this->assertSame('pass', $result['result']);
        $this->assertSame(13, $result['events']);   // 5 created + 4 links × 2 sides

        $this->assertSame(1, $this->inFarm($other, fn () => TraceEvent::max('farm_seq')));
        $this->artisan('trace:verify-chain')->assertSuccessful();
    }

    public function test_tampering_is_detected(): void
    {
        if (! $this->isPgsql()) {
            $this->markTestSkipped('Needs transactional DDL to switch the append-only trigger off inside the test.');
        }

        $this->chain();
        DB::statement('ALTER TABLE trace_events DISABLE TRIGGER trace_events_append_only');
        $this->inFarm($this->farm, fn () => DB::table('trace_events')->where('farm_seq', 3)->update(['payload' => json_encode(['quantity' => '9999'])]));
        DB::statement('ALTER TABLE trace_events ENABLE TRIGGER trace_events_append_only');

        $result = $this->inFarm($this->farm, fn () => $this->app->make(ChainVerifier::class)->verify());

        $this->assertSame('fail', $result['result']);
        $this->assertSame(3, $result['first_bad_seq']);
        $this->assertSame('hash_mismatch', $result['reason']);
        $this->assertSame('fail', $this->inFarm($this->farm, fn () => DB::table('trace_audits')->latest('created_at')->value('result')));
    }

    public function test_batch_ids_from_the_recorder_are_scoped_to_the_farm(): void
    {
        $batch = $this->inFarm($this->farm, fn () => $this->recorder->createBatch(BatchKind::SeedLot));

        $this->assertNull($this->inFarm($this->farm(), fn () => TraceBatch::find($batch->id)));
    }
}
