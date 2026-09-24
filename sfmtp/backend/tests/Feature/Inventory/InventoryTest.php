<?php

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Tenancy\Application\FarmSettings;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Domain\Models\TraceEvent;
use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    private Farm $farm;

    private User $owner;

    private User $store;

    private User $manager;

    private User $agronomist;

    private string $mainStore;

    private string $fieldStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->farm = $this->farm();
        $this->owner = $this->ownerOf($this->farm);
        $this->store = $this->memberWithRole($this->farm, 'store_manager');
        $this->manager = $this->memberWithRole($this->farm, 'manager');
        $this->agronomist = $this->memberWithRole($this->farm, 'agronomist');
        $this->mainStore = $this->as($this->owner)->postJson($this->url('/structure/locations'), ['code' => 'MAIN', 'name' => 'Main store', 'kind' => 'store'])->assertCreated()->json('data.id');
        $this->fieldStore = $this->as($this->owner)->postJson($this->url('/structure/locations'), ['code' => 'FIELD', 'name' => 'Field shed', 'kind' => 'store'])->assertCreated()->json('data.id');
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}{$path}";
    }

    private function as(User $user)
    {
        return $this->asUser($user);
    }

    private function item(array $data = []): array
    {
        return $this->as($this->store)->postJson($this->url('/inventory/items'), $data + [
            'name' => 'NPK 17-17-17', 'category_id' => DB::table('global_inventory_categories')->where('code', 'fertilizers')->value('id'), 'unit' => 'kg', 'reorder_level' => 100,
        ])->assertCreated()->json('data');
    }

    private function stockIn(array $item, float $qty, float $cost, array $extra = []): array
    {
        return $this->as($this->store)->postJson($this->url('/inventory/stock-in'), $extra + [
            'item_id' => $item['id'], 'location_id' => $this->mainStore, 'quantity' => $qty, 'unit_cost' => $cost,
        ])->assertCreated()->json('data');
    }

    /** @return array<string, float> account code => balance */
    private function trialBalance(): array
    {
        $data = $this->as($this->owner)->getJson($this->url('/ledger/accounts'))->assertOk()->json();
        $this->assertSame($data['meta']['total_debit'], $data['meta']['total_credit'], 'the ledger balances');

        return collect($data['data'])->pluck('balance', 'code')->all();
    }

    private function cycle(): array
    {
        $plot = $this->as($this->owner)->postJson($this->url('/structure/plots'), ['name' => 'A-1', 'declared_area_ha' => 2])->json('data.id');
        $crop = $this->as($this->agronomist)->postJson($this->url('/crops'), ['global_variety_id' => DB::table('global_crop_varieties')->where('code', 'longe_5')->value('id')])->json('data.id');

        return $this->as($this->agronomist)->postJson($this->url('/crop-cycles'), ['plot_id' => $plot, 'crop_id' => $crop, 'planted_on' => now()->subDays(20)->toDateString()])->assertCreated()->json('data');
    }

    public function test_lots_become_trace_batches_and_issues_go_first_expiry_first(): void
    {
        $item = $this->item(['tracks_expiry' => true]);
        $this->assertSame('ITM-001', $item['code']);
        $this->as($this->store)->postJson($this->url('/inventory/stock-in'), ['item_id' => $item['id'], 'location_id' => $this->mainStore, 'quantity' => 10])->assertStatus(422);   // needs an expiry
        $late = $this->stockIn($item, 100, 3000, ['lot_number' => 'NPK-B', 'expires_on' => now()->addYear()->toDateString()]);
        $early = $this->stockIn($item, 50, 2800, ['lot_number' => 'NPK-A', 'expires_on' => now()->addMonths(2)->toDateString()]);
        $this->assertSame('LOT-0001', $late['lot']['code']);

        // Each lot is an input_lot batch with a received history; no price in it.
        $batch = $this->inFarm($this->farm, fn () => TraceBatch::findOrFail($early['lot']['trace_batch_id']));
        $this->assertSame('input_lot', $batch->kind->value);
        $created = $this->inFarm($this->farm, fn () => TraceEvent::where('batch_id', $batch->id)->first());
        $this->assertArrayNotHasKey('unit_cost', $created->payload);

        // 70 kg to the maize: 50 from the earlier-expiring lot, 20 from the other.
        $cycle = $this->cycle();
        $issued = $this->as($this->store)->postJson($this->url('/inventory/issues'), [
            'item_id' => $item['id'], 'location_id' => $this->mainStore, 'quantity' => 70, 'subject_type' => 'crop_cycle', 'subject_id' => $cycle['id'],
        ])->assertCreated()->json('data');
        $this->assertEquals([['NPK-A', -50, -140000], ['NPK-B', -20, -60000]], array_map(fn ($m) => [$m['lot']['lot_number'], $m['quantity'], $m['value']], $issued));
        $this->assertContains('issued', $this->inFarm($this->farm, fn () => TraceEvent::where('batch_id', $batch->id)->pluck('event_type')->all()));

        // Not more than there is.
        $this->as($this->store)->postJson($this->url('/inventory/issues'), ['item_id' => $item['id'], 'location_id' => $this->mainStore, 'quantity' => 81])
            ->assertStatus(422)->assertJsonPath('code', 'insufficient_stock')->assertJsonPath('available', 80);

        // The money side: stock in at cost, issued to the crop cycle's cost centre.
        $tb = $this->trialBalance();
        $this->assertEquals(440000 - 200000, $tb['1300']);   // inventory
        $this->assertEquals(200000, $tb['5000']);            // inputs used
        $this->assertEquals(440000, $tb['3100']);            // opening balances
        $line = $this->inFarm($this->farm, fn () => DB::table('ledger_lines')->where('cost_center_type', 'crop_cycle')->first());
        $this->assertSame($cycle['id'], $line->cost_center_id);

        $item = $this->as($this->store)->getJson($this->url("/inventory/items/{$item['id']}"))->assertOk()->json('data');
        $this->assertEquals(80, $item['on_hand']);
        $this->assertTrue($item['low_stock']);
        $this->assertEquals(240000, $item['stock_value']);
    }

    public function test_untracked_stock_averages_cost_and_transfers_keep_value(): void
    {
        $diesel = $this->item(['name' => 'Diesel', 'category_id' => DB::table('global_inventory_categories')->where('code', 'fuel')->value('id'), 'unit' => 'l', 'tracks_lots' => false, 'reorder_level' => null]);
        $this->stockIn($diesel, 100, 5000);
        $this->stockIn($diesel, 100, 6000);   // average 5,500

        $t = $this->as($this->store)->postJson($this->url('/inventory/transfers'), [
            'from_location_id' => $this->mainStore, 'to_location_id' => $this->fieldStore, 'lines' => [['item_id' => $diesel['id'], 'quantity' => 40]],
        ])->assertCreated()->json('data');
        $this->assertSame('TRF-001', $t['code']);
        $stock = collect($this->as($this->store)->getJson($this->url("/inventory/stock?filter[item_id]={$diesel['id']}"))->json('data'))->keyBy('location.code');
        $this->assertEquals([160, 880000], [$stock['MAIN']['quantity'], $stock['MAIN']['value']]);
        $this->assertEquals([40, 220000], [$stock['FIELD']['quantity'], $stock['FIELD']['value']]);
        $this->assertEquals(1100000, $this->trialBalance()['1300'], 'transfers do not change the books');

        // Negative stock only when the farm allows it (untracked items).
        $this->as($this->store)->postJson($this->url('/inventory/issues'), ['item_id' => $diesel['id'], 'location_id' => $this->fieldStore, 'quantity' => 50])->assertStatus(422);
        $this->inFarm($this->farm, fn () => app(FarmSettings::class)->update($this->farm, ['allow_negative_stock' => true]));
        $other = $this->item(['name' => 'Petrol', 'category_id' => DB::table('global_inventory_categories')->where('code', 'fuel')->value('id'), 'unit' => 'l', 'tracks_lots' => false]);
        $this->as($this->store)->postJson($this->url('/inventory/issues'), ['item_id' => $other['id'], 'location_id' => $this->fieldStore, 'quantity' => 5])->assertCreated();
    }

    public function test_counts_need_approval_four_eyes_and_the_owner_above_the_threshold(): void
    {
        $seed = $this->item(['name' => 'Maize seed Longe 5', 'category_id' => DB::table('global_inventory_categories')->where('code', 'seeds')->value('id')]);
        $lot = $this->stockIn($seed, 100, 8000)['lot'];

        $adj = $this->as($this->store)->postJson($this->url('/inventory/adjustments'), [
            'location_id' => $this->mainStore, 'reason' => 'Monthly count: bag torn by rats', 'lines' => [['item_id' => $seed['id'], 'lot_id' => $lot['id'], 'counted_quantity' => 96]],
        ])->assertCreated()->json('data');
        $this->assertEquals(['ADJ-001', 100, -4], [$adj['code'], $adj['lines'][0]['expected_quantity'], $adj['lines'][0]['difference']]);
        $this->as($this->store)->postJson($this->url("/inventory/adjustments/{$adj['id']}/approve"))->assertForbidden();   // store has no approve
        $this->as($this->manager)->postJson($this->url("/inventory/adjustments/{$adj['id']}/approve"))->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonMissingPath('data.value_change');
        $this->assertEquals(-32000, $this->trialBalance()['1300'] - 800000);
        $this->assertEquals(32000, $this->trialBalance()['5100']);

        // Above the farm's threshold only the owner may approve.
        $this->inFarm($this->farm, fn () => app(FarmSettings::class)->update($this->farm, ['approval_thresholds' => ['stock_adjustment_pct' => 5]]));
        $big = $this->as($this->store)->postJson($this->url('/inventory/adjustments'), [
            'location_id' => $this->mainStore, 'reason' => 'Flooded store', 'lines' => [['item_id' => $seed['id'], 'lot_id' => $lot['id'], 'counted_quantity' => 60]],
        ])->json('data');
        $this->as($this->manager)->postJson($this->url("/inventory/adjustments/{$big['id']}/approve"))->assertForbidden()->assertJsonPath('code', 'approval_required');
        $this->as($this->owner)->postJson($this->url("/inventory/adjustments/{$big['id']}/approve"))->assertOk()->assertJsonPath('data.value_change', -288000);
        $this->assertEquals(60, $this->as($this->store)->getJson($this->url("/inventory/items/{$seed['id']}"))->json('data.on_hand'));
    }

    public function test_requests_are_approved_then_issued_to_the_work(): void
    {
        $feed = $this->item(['name' => 'Dairy meal', 'category_id' => DB::table('global_inventory_categories')->where('code', 'animal_feed')->value('id'), 'tracks_lots' => false]);
        $this->stockIn($feed, 500, 1500);
        $worker = $this->memberWithRole($this->farm, 'field_worker');

        // A field worker asks for feed; they see their own requests only, and no costs.
        $req = $this->as($worker)->postJson($this->url('/inventory/requests'), ['subject_type' => 'general', 'note' => 'For the calves', 'lines' => [['item_id' => $feed['id'], 'quantity' => 60]]])
            ->assertCreated()->assertJsonPath('data.code', 'REQ-001')->json('data');
        $this->as($worker)->getJson($this->url('/inventory/items'))->assertOk()->assertJsonMissingPath('data.0.stock_value');
        $this->as($worker)->getJson($this->url("/inventory/items/{$feed['id']}"))->assertForbidden();
        $other = $this->memberWithRole($this->farm, 'field_worker');
        $this->as($other)->getJson($this->url("/inventory/requests/{$req['id']}"))->assertNotFound();
        $this->as($other)->getJson($this->url('/inventory/requests'))->assertJsonCount(0, 'data');

        $this->as($this->store)->postJson($this->url("/inventory/requests/{$req['id']}/issue"), ['location_id' => $this->mainStore, 'lines' => [['line_id' => $req['lines'][0]['id'], 'quantity' => 60]]])
            ->assertStatus(409);   // not approved yet
        $this->as($this->manager)->postJson($this->url("/inventory/requests/{$req['id']}/approve"))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->as($this->store)->postJson($this->url("/inventory/requests/{$req['id']}/issue"), ['location_id' => $this->mainStore, 'lines' => [['line_id' => $req['lines'][0]['id'], 'quantity' => 40]]])
            ->assertOk()->assertJsonPath('data.status', 'partially_issued');
        $this->as($this->store)->postJson($this->url("/inventory/requests/{$req['id']}/issue"), ['location_id' => $this->mainStore, 'lines' => [['line_id' => $req['lines'][0]['id'], 'quantity' => 30]]])
            ->assertStatus(422);   // only 20 left on the request
        $this->as($this->store)->postJson($this->url("/inventory/requests/{$req['id']}/issue"), ['location_id' => $this->mainStore, 'lines' => [['line_id' => $req['lines'][0]['id'], 'quantity' => 20]]])
            ->assertOk()->assertJsonPath('data.status', 'issued')->assertJsonPath('data.lines.0.issued_quantity', 60);

        // Nobody approves their own request (except the owner).
        $own = $this->as($this->manager)->postJson($this->url('/inventory/requests'), ['lines' => [['item_id' => $feed['id'], 'quantity' => 5]]])->json('data');
        $this->as($this->manager)->postJson($this->url("/inventory/requests/{$own['id']}/approve"))->assertForbidden()->assertJsonPath('code', 'four_eyes');

        // The manager sees quantities, not values.
        $this->as($this->manager)->getJson($this->url('/inventory/movements'))->assertOk()->assertJsonMissingPath('data.0.value');
        $this->as($this->manager)->getJson($this->url('/ledger/accounts'))->assertForbidden();
        $this->assertEquals(90000, $this->trialBalance()['5000']);
    }

    public function test_the_ledger_is_append_only_and_reversible(): void
    {
        $item = $this->item(['tracks_lots' => false]);
        $this->stockIn($item, 10, 1000);
        $entry = $this->as($this->owner)->getJson($this->url('/ledger/entries'))->assertOk()->json('data.0');
        $this->assertSame('JE-00001', $entry['number']);
        $this->assertSame('opening_stock', $entry['source']['type']);

        $reversal = $this->as($this->owner)->postJson($this->url("/ledger/entries/{$entry['id']}/reverse"), ['reason' => 'Entered twice'])->assertCreated()->json('data');
        $this->assertSame($entry['id'], $reversal['reverses_entry_id']);
        $this->as($this->owner)->postJson($this->url("/ledger/entries/{$entry['id']}/reverse"), ['reason' => 'Again'])->assertStatus(409);
        $this->assertEquals(0, $this->trialBalance()['1300']);
        $this->as($this->store)->postJson($this->url("/ledger/entries/{$entry['id']}/reverse"), ['reason' => 'x'])->assertForbidden();

        $movement = $this->inFarm($this->farm, fn () => StockMovement::firstOrFail());
        $this->assertThrows(fn () => $this->inFarm($this->farm, fn () => $movement->forceFill(['quantity' => 1])->save()), AppendOnlyViolation::class);
        $this->expectException(QueryException::class);
        $this->inFarm($this->farm, fn () => DB::table('ledger_lines')->update(['debit' => 1]));
    }

    public function test_postgres_refuses_an_unbalanced_entry_at_commit(): void
    {
        if (! $this->isPgsql()) {
            $this->markTestSkipped('The deferred balance trigger is PostgreSQL-only; the service checks on every engine.');
        }
        $item = $this->item(['tracks_lots' => false]);
        $this->stockIn($item, 10, 1000);
        $this->expectExceptionMessage('SFMTP_UNBALANCED');
        $this->inFarm($this->farm, function () {
            $line = DB::table('ledger_lines')->first();
            DB::table('ledger_lines')->insert(['id' => (string) Str::uuid7(), 'farm_id' => $line->farm_id, 'entry_id' => $line->entry_id, 'account_id' => $line->account_id, 'debit' => 5, 'credit' => 0]);
            DB::statement('SET CONSTRAINTS ledger_lines_balanced IMMEDIATE');
        });
    }
}
