<?php

namespace Tests\Feature\Procurement;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\FarmSettings;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Domain\Models\TraceEvent;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcurementTest extends TestCase
{
    private Farm $farm;

    private User $owner;

    private User $store;

    private User $manager;

    private User $accountant;

    private string $mainStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->farm = $this->farm();
        $this->owner = $this->ownerOf($this->farm);
        $this->store = $this->memberWithRole($this->farm, 'store_manager');
        $this->manager = $this->memberWithRole($this->farm, 'manager');
        $this->accountant = $this->memberWithRole($this->farm, 'accountant');
        $this->mainStore = $this->as($this->owner)->postJson($this->url('/structure/locations'), ['code' => 'MAIN', 'name' => 'Main store', 'kind' => 'store'])->json('data.id');
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}{$path}";
    }

    private function as(User $user)
    {
        return $this->asUser($user);
    }

    private function item(string $name, string $category, string $unit = 'kg', array $extra = []): array
    {
        return $this->as($this->store)->postJson($this->url('/inventory/items'), $extra + [
            'name' => $name, 'category_id' => DB::table('global_inventory_categories')->where('code', $category)->value('id'), 'unit' => $unit,
        ])->assertCreated()->json('data');
    }

    /** @return array<string, float> */
    private function balances(): array
    {
        $tb = $this->as($this->accountant)->getJson($this->url('/ledger/accounts'))->assertOk()->json();
        $this->assertEquals($tb['meta']['total_debit'], $tb['meta']['total_credit']);

        return collect($tb['data'])->pluck('balance', 'code')->all();
    }

    public function test_request_to_order_to_delivery_to_invoice(): void
    {
        $npk = $this->item('NPK 17-17-17', 'fertilizers');
        $seed = $this->item('Maize seed Longe 5', 'seeds', 'kg', ['tracks_expiry' => true]);
        $supplier = $this->as($this->accountant)->postJson($this->url('/suppliers'), ['name' => 'Kakiri Agro Inputs', 'phone' => '+256 700 111222', 'payment_terms_days' => 30])
            ->assertCreated()->assertJsonPath('data.code', 'SUP-001')->json('data');

        // The store asks; the manager approves.
        $pr = $this->as($this->store)->postJson($this->url('/purchase-requests'), ['reason' => 'Season B top dressing', 'lines' => [
            ['item_id' => $npk['id'], 'quantity' => 500], ['item_id' => $seed['id'], 'quantity' => 100],
        ]])->assertCreated()->assertJsonPath('data.code', 'PR-001')->json('data');
        $this->as($this->store)->postJson($this->url('/purchase-requests'), ['lines' => [['item_id' => $npk['id'], 'quantity' => 1, 'estimated_unit_price' => 3000]]])
            ->assertForbidden()->assertJsonPath('code', 'money_field_forbidden');
        $this->as($this->manager)->postJson($this->url("/purchase-requests/{$pr['id']}/approve"))->assertOk()->assertJsonPath('data.status', 'approved');

        // The store cannot buy; the accountant raises the order.
        $this->as($this->store)->postJson($this->url('/purchase-orders'), ['supplier_id' => $supplier['id'], 'lines' => [['item_id' => $npk['id'], 'quantity' => 1, 'unit_price' => 1]]])->assertForbidden();
        $po = $this->as($this->accountant)->postJson($this->url('/purchase-orders'), [
            'supplier_id' => $supplier['id'], 'purchase_request_id' => $pr['id'], 'delivery_location_id' => $this->mainStore, 'lines' => [
                ['item_id' => $npk['id'], 'quantity' => 500, 'unit_price' => 3000],
                ['item_id' => $seed['id'], 'quantity' => 100, 'unit_price' => 8000],
            ],
        ])->assertCreated()->assertJsonPath('data.code', 'PO-001')->assertJsonPath('data.total_amount', 2300000)->json('data');
        $this->as($this->manager)->getJson($this->url("/purchase-requests/{$pr['id']}"))->assertJsonPath('data.status', 'ordered');

        // Approval: not by the buyer, and above the threshold only by the owner.
        $this->inFarm($this->farm, fn () => app(FarmSettings::class)->update($this->farm, ['approval_thresholds' => ['purchase_order' => 1000000]]));
        $this->as($this->accountant)->postJson($this->url("/purchase-orders/{$po['id']}/approve"))->assertForbidden();   // no approve permission
        $this->as($this->owner)->postJson($this->url("/purchase-orders/{$po['id']}/approve"))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->as($this->accountant)->postJson($this->url("/purchase-orders/{$po['id']}/send"))->assertOk()->assertJsonPath('data.status', 'sent');

        // The store receives what came, by lot, and sees no prices.
        $seen = $this->as($this->store)->getJson($this->url("/purchase-orders/{$po['id']}"))->assertOk()->assertJsonMissingPath('data.total_amount')->assertJsonMissingPath('data.lines.0.unit_price')->json('data');
        [$npkLine, $seedLine] = [collect($seen['lines'])->firstWhere('item.id', $npk['id']), collect($seen['lines'])->firstWhere('item.id', $seed['id'])];
        $this->as($this->store)->postJson($this->url("/purchase-orders/{$po['id']}/deliveries"), ['location_id' => $this->mainStore, 'lines' => [['order_line_id' => $npkLine['id'], 'quantity' => 501]]])
            ->assertStatus(422);
        $this->as($this->store)->postJson($this->url("/purchase-orders/{$po['id']}/deliveries"), ['location_id' => $this->mainStore, 'lines' => [['order_line_id' => $seedLine['id'], 'quantity' => 100]]])
            ->assertStatus(422);   // seed needs an expiry date
        $first = $this->as($this->store)->postJson($this->url("/purchase-orders/{$po['id']}/deliveries"), [
            'location_id' => $this->mainStore, 'supplier_reference' => 'DN-4471', 'lines' => [
                ['order_line_id' => $npkLine['id'], 'quantity' => 300, 'lot_number' => 'NPK-2609'],
                ['order_line_id' => $seedLine['id'], 'quantity' => 100, 'lot_number' => 'L5-0925', 'expires_on' => now()->addYear()->toDateString()],
            ],
        ])->assertCreated()->assertJsonPath('data.status', 'partially_received')->assertJsonPath('meta.delivery.code', 'GRN-001')->json('data');
        $this->as($this->store)->postJson($this->url("/purchase-orders/{$po['id']}/deliveries"), ['location_id' => $this->mainStore, 'lines' => [['order_line_id' => $npkLine['id'], 'quantity' => 200, 'lot_number' => 'NPK-2610']]])
            ->assertCreated()->assertJsonPath('data.status', 'received');

        // The seed lot is an input_lot batch naming the supplier and the order.
        $lot = $first['deliveries'][0]['lines'][1]['lot'];
        $batch = $this->inFarm($this->farm, fn () => TraceBatch::findOrFail($lot['trace_batch_id']));
        $created = $this->inFarm($this->farm, fn () => TraceEvent::where('batch_id', $batch->id)->where('event_type', 'created')->firstOrFail());
        $this->assertSame(['SUP-001', 'PO-001', 'L5-0925'], [$created->payload['supplier'], $created->payload['order'], $created->payload['lot_number']]);
        $this->assertEquals(500, $this->as($this->store)->getJson($this->url("/inventory/items/{$npk['id']}"))->json('data.on_hand'));

        // Stock is in at the order price, owed as goods received not invoiced.
        $b = $this->balances();
        $this->assertEquals([2300000, 2300000], [$b['1300'], $b['2100']]);

        // The invoice is matched to what was received; the price difference goes to variance.
        $this->as($this->accountant)->postJson($this->url("/purchase-orders/{$po['id']}/invoices"), ['invoice_number' => 'INV-88', 'invoice_date' => now()->toDateString(), 'lines' => [
            ['order_line_id' => $npkLine['id'], 'quantity' => 600, 'unit_price' => 3000],
        ]])->assertStatus(422);   // more than received
        $inv = $this->as($this->accountant)->postJson($this->url("/purchase-orders/{$po['id']}/invoices"), ['invoice_number' => 'INV-88', 'invoice_date' => now()->toDateString(), 'lines' => [
            ['order_line_id' => $npkLine['id'], 'quantity' => 500, 'unit_price' => 3100],
            ['order_line_id' => $seedLine['id'], 'quantity' => 100, 'unit_price' => 8000],
        ]])->assertCreated()->assertJsonPath('data.amount', 2350000)->assertJsonPath('data.due_on', now()->addDays(30)->toDateString())->json('data');
        $this->as($this->accountant)->postJson($this->url("/purchase-orders/{$po['id']}/invoices"), ['invoice_number' => 'INV-88', 'invoice_date' => now()->toDateString(), 'lines' => [['order_line_id' => $npkLine['id'], 'quantity' => 1, 'unit_price' => 1]]])
            ->assertStatus(409);
        $b = $this->balances();
        $this->assertEquals([0, 2350000, 50000], [$b['2100'], $b['2000'], $b['5200']]);
        $this->as($this->accountant)->getJson($this->url("/purchase-orders/{$po['id']}"))->assertJsonPath('data.status', 'closed')->assertJsonPath('data.invoices.0.code', $inv['code']);
    }

    public function test_four_eyes_cancellation_and_boundaries(): void
    {
        $item = $this->item('Dairy meal', 'animal_feed', 'kg', ['tracks_lots' => false]);
        $supplier = $this->as($this->accountant)->postJson($this->url('/suppliers'), ['name' => 'Wakiso Feeds'])->json('data');
        $po = $this->as($this->owner)->postJson($this->url('/purchase-orders'), ['supplier_id' => $supplier['id'], 'lines' => [['item_id' => $item['id'], 'quantity' => 50, 'unit_price' => 1500]]])->json('data');
        // The owner may approve their own order; nobody else could.
        $this->as($this->owner)->postJson($this->url("/purchase-orders/{$po['id']}/approve"))->assertOk();
        $this->as($this->store)->postJson($this->url("/purchase-orders/{$po['id']}/deliveries"), ['location_id' => $this->mainStore, 'lines' => [['order_line_id' => $po['lines'][0]['id'], 'quantity' => 20]]])->assertCreated();
        $this->as($this->accountant)->postJson($this->url("/purchase-orders/{$po['id']}/cancel"), ['reason' => 'Changed our mind'])->assertStatus(409);
        $this->as($this->accountant)->postJson($this->url("/purchase-orders/{$po['id']}/close"))->assertOk()->assertJsonPath('data.status', 'closed');

        $pr = $this->as($this->manager)->postJson($this->url('/purchase-requests'), ['lines' => [['description' => 'Wheelbarrow', 'quantity' => 2, 'unit' => 'pcs']]])->assertCreated()->json('data');
        $this->as($this->manager)->postJson($this->url("/purchase-requests/{$pr['id']}/approve"))->assertForbidden()->assertJsonPath('code', 'four_eyes');

        $worker = $this->memberWithRole($this->farm, 'field_worker');
        $this->as($worker)->getJson($this->url('/purchase-orders'))->assertForbidden();
        $this->as($worker)->getJson($this->url('/suppliers'))->assertForbidden();
        $this->as($this->manager)->getJson($this->url('/supplier-invoices'))->assertForbidden();
        $this->as($this->manager)->getJson($this->url("/purchase-orders/{$po['id']}"))->assertForbidden();   // managers approve requests, not orders
    }
}
