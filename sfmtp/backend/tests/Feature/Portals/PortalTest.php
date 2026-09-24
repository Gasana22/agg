<?php

namespace Tests\Feature\Portals;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Parties\Notifications\PortalInvitationSent;
use App\Modules\Procurement\Notifications\PurchaseOrderSent;
use App\Modules\Tenancy\Application\FarmSettings;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Supplier and customer portals (Phase 12, ADR-0016): invitations, party
 * accounts across farms, the supplier's order flow, the customer's order
 * → invoice → dispatch → delivered flow, and party isolation (docs/04 §6
 * tests 9 and 10).
 */
class PortalTest extends TestCase
{
    private Farm $farm;

    private User $owner;

    private User $manager;

    private User $store;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        Notification::fake();
        $this->farm = $this->farm(attributes: ['name' => 'Green Hill Farm']);
        $this->owner = $this->ownerOf($this->farm);
        $this->manager = $this->memberWithRole($this->farm, 'manager');
        $this->store = $this->memberWithRole($this->farm, 'store_manager');
        $this->accountant = $this->memberWithRole($this->farm, 'accountant');
    }

    private function url(string $path, ?Farm $farm = null): string
    {
        return '/api/v1/farms/'.($farm ?? $this->farm)->id.$path;
    }

    /** Invite a supplier or customer record and return the emailed token. */
    private function invite(User $by, string $kind, string $recordId, string $email, ?Farm $farm = null): string
    {
        $this->asUser($by)->postJson($this->url('/portal-invitations', $farm), ['kind' => $kind, 'record_id' => $recordId, 'email' => $email])->assertCreated();
        $token = null;
        Notification::assertSentOnDemand(PortalInvitationSent::class, function (PortalInvitationSent $n, array $channels, AnonymousNotifiable $to) use ($email, &$token) {
            if ($to->routes['mail'] === mb_strtolower($email)) {
                $token = $n->token;
            }

            return true;
        });

        return $token;
    }

    /** @return array{0: User, 1: string} the new portal user and their party id */
    private function joinAsNew(string $token, string $name, string $email): array
    {
        $this->app['auth']->forgetGuards();
        $res = $this->withHeaders(['Authorization' => ''])->postJson("/api/v1/portal-invitations/{$token}/accept", ['name' => $name, 'password' => 'Portal-pass-2026', 'password_confirmation' => 'Portal-pass-2026'])
            ->assertCreated()->assertJsonPath('data.account_created', true);

        return [User::where('email', $email)->firstOrFail(), $res->json('data.party.id')];
    }

    /** Read a farm table directly (row-level security needs the farm context). */
    private function farmRow(string $table, array $where, string $column): mixed
    {
        return $this->inFarm($this->farm, fn () => DB::table($table)->where($where)->value($column));
    }

    private function supplier(string $name, string $email, ?Farm $farm = null): string
    {
        return $this->asUser($farm ? $this->ownerOf($farm) : $this->owner)->postJson($this->url('/suppliers', $farm), ['name' => $name, 'email' => $email, 'payment_terms_days' => 30])->assertCreated()->json('data.id');
    }

    private function customer(string $name, ?Farm $farm = null): string
    {
        return $this->asUser($farm ? $this->ownerOf($farm) : $this->owner)->postJson($this->url('/customers', $farm), ['name' => $name, 'address' => 'Plot 4, Kampala Road'])->assertCreated()->json('data.id');
    }

    private function item(string $name, string $unit = 'kg'): string
    {
        return $this->asUser($this->store)->postJson($this->url('/inventory/items'), [
            'name' => $name, 'category_id' => DB::table('global_inventory_categories')->where('code', 'fertilizers')->value('id'), 'unit' => $unit,
        ])->assertCreated()->json('data.id');
    }

    /** A sent purchase order to a supplier: 500 kg at 3,000 and 100 kg at 5,000. */
    private function sentOrder(string $supplierId): array
    {
        [$npk, $urea] = [$this->item('NPK 17-17-17'), $this->item('Urea')];
        $order = $this->asUser($this->accountant)->postJson($this->url('/purchase-orders'), ['supplier_id' => $supplierId, 'expected_on' => now()->addWeek()->toDateString(), 'notes' => 'Internal: they were late last time', 'lines' => [
            ['item_id' => $npk, 'quantity' => 500, 'unit_price' => 3000],
            ['item_id' => $urea, 'quantity' => 100, 'unit_price' => 5000],
        ]])->assertCreated()->json('data');
        $this->asUser($this->owner)->postJson($this->url("/purchase-orders/{$order['id']}/approve"))->assertOk();
        $this->asUser($this->accountant)->postJson($this->url("/purchase-orders/{$order['id']}/send"))->assertOk();

        return $order;
    }

    public function test_an_invitation_makes_one_party_account_across_farms(): void
    {
        $supplierId = $this->supplier('Kakiri Agro Inputs', 'sales@kakiri.test');
        $token = $this->invite($this->owner, 'supplier', $supplierId, 'Sales@Kakiri.test');

        // The store cannot open portal access; the preview needs no sign-in.
        $this->asUser($this->store)->postJson($this->url('/portal-invitations'), ['kind' => 'supplier', 'record_id' => $supplierId, 'email' => 'x@y.test'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson("/api/v1/portal-invitations/{$token}")->assertOk()
            ->assertJsonPath('data.kind', 'supplier')->assertJsonPath('data.record_name', 'Kakiri Agro Inputs')->assertJsonPath('data.farm.name', 'Green Hill Farm')
            ->assertJsonPath('data.account_exists', false);

        [$sales, $party] = $this->joinAsNew($token, 'Sarah Sales', 'sales@kakiri.test');
        $this->assertSame('party', $sales->user_type->value);
        $this->assertSame($party, $this->farmRow('suppliers', ['id' => $supplierId], 'party_id'));
        $this->withHeaders(['Authorization' => ''])->postJson("/api/v1/portal-invitations/{$token}/accept", ['name' => 'Again', 'password' => 'Portal-pass-2026', 'password_confirmation' => 'Portal-pass-2026'])
            ->assertStatus(410)->assertJsonPath('code', 'invitation_used');
        $this->assertProblem($this->asUser($this->owner)->postJson($this->url('/portal-invitations'), ['kind' => 'supplier', 'record_id' => $supplierId, 'email' => 'b@kakiri.test']), 409, 'already_linked');

        // A second farm invites the same person: the link joins the same party.
        $other = $this->farm(attributes: ['name' => 'Lakeside Farm']);
        $otherSupplier = $this->supplier('Kakiri Agro (Lakeside account)', 'sales@kakiri.test', $other);
        $token2 = $this->invite($this->ownerOf($other), 'supplier', $otherSupplier, 'sales@kakiri.test', $other);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->postJson("/api/v1/portal-invitations/{$token2}/accept")->assertStatus(409)->assertJsonPath('code', 'sign_in_required');
        $this->asUser($sales)->postJson("/api/v1/portal-invitations/{$token2}/accept")->assertOk()->assertJsonPath('data.party.id', $party);

        $ws = collect($this->asUser($sales)->getJson('/api/v1/me/workspaces')->assertOk()->json('data'));
        $this->assertSame(['supplier'], $ws->pluck('type')->all());
        $this->assertEqualsCanonicalizing(['Green Hill Farm', 'Lakeside Farm'], array_column($ws[0]['farms'], 'name'));

        // A party account is not a farm member: every farm route is a 404.
        $this->asUser($sales)->getJson($this->url('/purchase-orders'))->assertNotFound();
        $this->asUser($sales)->getJson($this->url('/inventory/items'))->assertNotFound();

        // A farm member who is also a customer elsewhere sees both workspaces, with one sign-in.
        $customerId = $this->customer('Manager Moses (personal)', $other);
        $token3 = $this->invite($this->ownerOf($other), 'customer', $customerId, $this->manager->email, $other);
        $this->asUser($this->manager)->postJson("/api/v1/portal-invitations/{$token3}/accept")->assertOk()->assertJsonPath('data.kind', 'customer');
        $types = collect($this->asUser($this->manager)->getJson('/api/v1/me/workspaces')->json('data'))->pluck('type')->all();
        $this->assertSame(['farm', 'customer'], $types);

        // Unlinking stops access at once.
        $link = collect($this->asUser($this->owner)->getJson($this->url('/portal-access'))->assertOk()->json('data'))->firstWhere('type', 'portal_link');
        $this->assertSame('Kakiri Agro Inputs', $link['record_name']);
        $this->asUser($this->owner)->deleteJson($this->url("/portal-links/{$link['id']}"))->assertNoContent();
        $this->assertNull($this->farmRow('suppliers', ['id' => $supplierId], 'party_id'));
        $farms = $this->asUser($sales)->getJson('/api/v1/me/workspaces')->json('data.0.farms');
        $this->assertSame(['Lakeside Farm'], array_column($farms, 'name'));
    }

    public function test_a_supplier_answers_dispatches_and_invoices_through_the_portal(): void
    {
        $supplierId = $this->supplier('Kakiri Agro Inputs', 'sales@kakiri.test');
        [$sales, $party] = $this->joinAsNew($this->invite($this->owner, 'supplier', $supplierId, 'sales@kakiri.test'), 'Sarah Sales', 'sales@kakiri.test');
        $lime = $this->item('Lime');
        $draft = $this->asUser($this->accountant)->postJson($this->url('/purchase-orders'), ['supplier_id' => $supplierId, 'lines' => [['item_id' => $lime, 'quantity' => 1, 'unit_price' => 10]]])->json('data');
        $order = $this->sentOrder($supplierId);
        Notification::assertSentTo($sales, PurchaseOrderSent::class);
        $base = "/api/v1/supplier/{$party}";
        $po = "{$base}/farms/{$this->farm->id}/orders/{$order['id']}";

        // Only sent orders reach the portal, without internal notes or people.
        $list = $this->asUser($sales)->getJson("{$base}/orders")->assertOk()->json('data');
        $this->assertSame([$order['id']], array_column($list, 'id'));
        $this->asUser($sales)->getJson("{$base}/farms/{$this->farm->id}/orders/{$draft['id']}")->assertNotFound();
        $detail = $this->asUser($sales)->getJson($po)->assertOk()->assertJsonPath('data.farm.name', 'Green Hill Farm')->assertJsonPath('data.total_amount', 2000000)->json('data');
        $json = json_encode($detail);
        $this->assertStringNotContainsString('late last time', $json);
        $this->assertArrayNotHasKey('created_by', $detail);

        // Accept, confirming 400 of the 500 kg.
        [$npk, $urea] = array_column($detail['lines'], 'id');
        $this->asUser($sales)->postJson("{$po}/respond", ['decision' => 'rejected'])->assertUnprocessable();
        $this->asUser($sales)->postJson("{$po}/respond", ['decision' => 'accepted', 'promised_on' => now()->addDays(5)->toDateString(),
            'lines' => [['line_id' => $npk, 'confirmed_quantity' => 400]]])->assertOk()
            ->assertJsonPath('data.supplier_response', 'accepted')->assertJsonPath('data.lines.0.confirmed_quantity', 400)->assertJsonPath('data.lines.1.confirmed_quantity', 100);
        $this->asUser($this->accountant)->getJson($this->url("/purchase-orders/{$order['id']}"))
            ->assertJsonPath('data.supplier_response', 'accepted')->assertJsonPath('data.supplier.on_portal', true);
        $this->assertNotNull($this->farmRow('member_notifications', ['kind' => 'supplier_response', 'user_id' => $this->accountant->id], 'id'));

        // A dispatch notice with the delivery note; the store receives against it.
        $upload = $this->asUser($sales)->post("{$base}/farms/{$this->farm->id}/media", ['file' => UploadedFile::fake()->image('note.jpg')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->asUser($sales)->postJson("{$po}/dispatches", ['lines' => [['order_line_id' => $npk, 'quantity' => 600]]])->assertUnprocessable();
        $dispatch = $this->asUser($sales)->postJson("{$po}/dispatches", ['reference' => 'DN-7781', 'media_id' => $upload, 'expected_on' => now()->addDay()->toDateString(), 'lines' => [
            ['order_line_id' => $npk, 'quantity' => 400], ['order_line_id' => $urea, 'quantity' => 100],
        ]])->assertCreated()->assertJsonPath('data.dispatches.0.code', 'ASN-001')->assertJsonPath('data.lines.0.on_the_way', 400)->json('data.dispatches.0');
        $this->asUser($sales)->getJson("{$base}/dashboard")->assertOk()->assertJsonPath('data.deliveries_in_transit', 1);

        $location = $this->asUser($this->owner)->postJson($this->url('/structure/locations'), ['code' => 'MAIN', 'name' => 'Main store', 'kind' => 'store'])->json('data.id');
        $this->asUser($this->store)->postJson($this->url("/purchase-orders/{$order['id']}/deliveries"), ['location_id' => $location, 'dispatch_id' => $dispatch['id'], 'lines' => [
            ['order_line_id' => $npk, 'quantity' => 400], ['order_line_id' => $urea, 'quantity' => 100],
        ]])->assertCreated()->assertJsonPath('data.deliveries.0.supplier_reference', 'DN-7781')->assertJsonPath('data.dispatches.0.status', 'received');
        // The store may open the delivery note the supplier uploaded.
        $this->asUser($this->store)->getJson($this->url("/media/{$upload}"))->assertOk();

        // Invoice through the portal; the accountant records it (three-way match, ledger).
        $this->asUser($sales)->postJson("{$po}/invoices", ['invoice_number' => 'KAI-2026-114', 'invoice_date' => now()->toDateString(), 'lines' => [
            ['order_line_id' => $npk, 'quantity' => 500, 'unit_price' => 3000],
        ]])->assertUnprocessable();
        $this->asUser($sales)->postJson("{$po}/invoices", ['invoice_number' => 'KAI-2026-114', 'invoice_date' => now()->toDateString(), 'lines' => [
            ['order_line_id' => $npk, 'quantity' => 400, 'unit_price' => 3000], ['order_line_id' => $urea, 'quantity' => 100, 'unit_price' => 5100],
        ]])->assertCreated()->assertJsonPath('data.submissions.0.status', 'submitted')->assertJsonPath('data.submissions.0.amount', 1710000);
        $this->assertProblem($this->asUser($sales)->postJson("{$po}/invoices", ['invoice_number' => 'KAI-2026-114', 'invoice_date' => now()->toDateString(), 'lines' => [
            ['order_line_id' => $npk, 'quantity' => 1, 'unit_price' => 1],
        ]]), 409, 'duplicate');

        $this->asUser($this->store)->getJson($this->url('/supplier-invoice-submissions'))->assertForbidden();
        $submission = $this->asUser($this->accountant)->getJson($this->url('/supplier-invoice-submissions?filter[status]=submitted'))->assertOk()->json('data.0');
        $this->assertSame('NPK 17-17-17', $submission['lines'][0]['item']['name']);
        $this->asUser($this->accountant)->postJson($this->url("/supplier-invoice-submissions/{$submission['id']}/record"))->assertOk()
            ->assertJsonPath('data.status', 'recorded')->assertJsonPath('data.supplier_invoice.code', 'SINV-001');
        $this->assertProblem($this->asUser($this->accountant)->postJson($this->url("/supplier-invoice-submissions/{$submission['id']}/reject"), ['reason' => 'late']), 409, 'invalid_state_transition');

        $invoices = $this->asUser($sales)->getJson("{$base}/invoices")->assertOk()->json('data');
        $this->assertSame(['supplier_invoice'], array_column($invoices, 'type'));
        $this->assertSame('recorded', $invoices[0]['status']);
        $this->assertEquals(1710000, $invoices[0]['outstanding']);
        // 400 of the 500 kg came: the order is still open.
        $this->asUser($sales)->getJson("{$base}/dashboard")->assertJsonPath('data.completed_orders', 0)->assertJsonPath('data.pending_orders', 1)
            ->assertJsonPath('data.money.0.outstanding_invoices', 1710000)->assertJsonPath('data.deliveries_in_transit', 0);
        $this->asUser($sales)->postJson("{$po}/respond", ['decision' => 'accepted'])->assertStatus(409);
    }

    public function test_a_supplier_sees_only_its_own_orders(): void
    {
        $mine = $this->supplier('Kakiri Agro Inputs', 'sales@kakiri.test');
        $rival = $this->supplier('Rival Seeds', 'hello@rival.test');
        [$sales, $party] = $this->joinAsNew($this->invite($this->owner, 'supplier', $mine, 'sales@kakiri.test'), 'Sarah Sales', 'sales@kakiri.test');
        [$rivalUser, $rivalParty] = $this->joinAsNew($this->invite($this->owner, 'supplier', $rival, 'hello@rival.test'), 'Rita Rival', 'hello@rival.test');
        $rivalOrder = $this->sentOrder($rival);
        $other = $this->farm();
        $otherOrderFarm = $other->id;

        $this->asUser($sales)->getJson("/api/v1/supplier/{$party}/orders")->assertOk()->assertJsonCount(0, 'data');
        // Another supplier's order, even in a linked farm: 404, and no response possible.
        $this->asUser($sales)->getJson("/api/v1/supplier/{$party}/farms/{$this->farm->id}/orders/{$rivalOrder['id']}")->assertNotFound();
        $this->asUser($sales)->postJson("/api/v1/supplier/{$party}/farms/{$this->farm->id}/orders/{$rivalOrder['id']}/respond", ['decision' => 'accepted'])->assertNotFound();
        // A farm it is not linked to, another party's id, the customer portal: 404.
        $this->asUser($sales)->getJson("/api/v1/supplier/{$party}/farms/{$otherOrderFarm}/orders/{$rivalOrder['id']}")->assertNotFound();
        $this->asUser($sales)->getJson("/api/v1/supplier/{$rivalParty}/orders")->assertNotFound();
        $this->asUser($sales)->getJson("/api/v1/customer/{$party}/orders")->assertNotFound();
        // Farm members are not the party either.
        $this->asUser($this->owner)->getJson("/api/v1/supplier/{$party}/orders")->assertNotFound();
        $this->asUser($rivalUser)->getJson("/api/v1/supplier/{$rivalParty}/orders")->assertOk()->assertJsonCount(1, 'data');
        // Internal data never reaches a supplier (docs/04 §6 test 9).
        foreach (['/tasks', '/workers', '/inventory/items', '/crops/cycles', '/purchase-orders', '/suppliers'] as $path) {
            $this->asUser($sales)->getJson($this->url($path))->assertNotFound();
        }
        // A suspended farm drops out of the portal.
        $this->farm->forceFill(['status' => 'suspended'])->save();
        $this->asUser($rivalUser)->getJson("/api/v1/supplier/{$rivalParty}/orders")->assertNotFound();
    }

    public function test_customer_order_to_invoice_to_dispatch_to_delivered(): void
    {
        $customerId = $this->customer('Kampala Grocers');
        [$buyer, $party] = $this->joinAsNew($this->invite($this->owner, 'customer', $customerId, 'buy@grocers.test'), 'Grace Grocer', 'buy@grocers.test');
        $base = "/api/v1/customer/{$party}";
        $farmBase = "{$base}/farms/{$this->farm->id}";

        // Products: the owner prices them; only published ones reach the portal.
        $this->asUser($this->manager)->postJson($this->url('/products'), ['name' => 'Eggs (tray of 30)', 'unit' => 'tray', 'list_price' => 12000])->assertForbidden();
        $eggs = $this->asUser($this->owner)->postJson($this->url('/products'), ['name' => 'Eggs (tray of 30)', 'unit' => 'tray', 'list_price' => 12000, 'min_order_quantity' => 5, 'is_published' => true])
            ->assertCreated()->assertJsonPath('data.code', 'PRD-001')->json('data');
        $this->asUser($this->owner)->postJson($this->url('/products'), ['name' => 'Secret blend', 'unit' => 'kg', 'list_price' => 1])->assertCreated();
        $products = $this->asUser($buyer)->getJson("{$base}/products")->assertOk()->json('data');
        $this->assertSame(['Eggs (tray of 30)'], array_column($products, 'name'));
        $this->assertArrayNotHasKey('inventory_item', $products[0]);

        // Order at list price; the minimum applies.
        $this->asUser($buyer)->postJson("{$farmBase}/orders", ['lines' => [['product_id' => $eggs['id'], 'quantity' => 2]]])->assertUnprocessable();
        $order = $this->asUser($buyer)->postJson("{$farmBase}/orders", ['note' => 'Before 9 am please', 'lines' => [['product_id' => $eggs['id'], 'quantity' => 50]]])
            ->assertCreated()->assertJsonPath('data.status', 'requested')->assertJsonPath('data.total_amount', 600000)->assertJsonPath('data.code', 'SO-0001')->json('data');
        $this->assertNotNull($this->farmRow('member_notifications', ['kind' => 'sales_order', 'user_id' => $this->manager->id], 'id'));

        // Above the threshold only the owner approves.
        $this->inFarm($this->farm, fn () => app(FarmSettings::class)->update($this->farm, ['approval_thresholds' => ['sales_order' => 500000]]));
        $this->assertProblem($this->asUser($this->manager)->postJson($this->url("/sales-orders/{$order['id']}/approve")), 403, 'approval_required');
        $this->asUser($this->owner)->postJson($this->url("/sales-orders/{$order['id']}/approve"))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertProblem($this->asUser($buyer)->postJson("{$farmBase}/orders/{$order['id']}/cancel", ['reason' => 'changed mind']), 409, 'invalid_state_transition');

        // The accountant invoices it; the customer sees the invoice only once issued.
        $this->asUser($this->store)->postJson($this->url("/sales-orders/{$order['id']}/invoice"))->assertForbidden();
        $invoiceId = $this->asUser($this->accountant)->postJson($this->url("/sales-orders/{$order['id']}/invoice"))->assertCreated()
            ->assertJsonPath('data.status', 'invoiced')->json('data.invoice.id');
        $this->asUser($buyer)->getJson("{$base}/invoices")->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->accountant)->postJson($this->url("/customer-invoices/{$invoiceId}/issue"))->assertOk();
        $this->asUser($buyer)->getJson("{$base}/invoices")->assertJsonPath('data.0.amount', 600000)->assertJsonPath('data.0.outstanding', 600000)
            ->assertJsonPath('data.0.lines.0.description', 'Eggs (tray of 30)');

        // The store dispatches from a trace batch, without seeing prices.
        $batch = $this->inFarm($this->farm, fn () => app(Recorder::class)->createBatch(BatchKind::AnimalProduct, ['name' => 'Eggs 24 Sep', 'quantity' => '80', 'unit' => 'tray']))->id;
        $wrongUnit = $this->inFarm($this->farm, fn () => app(Recorder::class)->createBatch(BatchKind::AnimalProduct, ['name' => 'Loose eggs', 'quantity' => '900', 'unit' => 'pcs']))->id;
        $view = $this->asUser($this->store)->getJson($this->url("/sales-orders/{$order['id']}"))->assertOk()->json('data');
        $this->assertArrayNotHasKey('total_amount', $view);
        $this->assertArrayNotHasKey('unit_price', $view['lines'][0]);
        $line = $view['lines'][0]['id'];
        $this->asUser($this->store)->postJson($this->url("/sales-orders/{$order['id']}/dispatch"), ['lines' => [['order_line_id' => $line, 'batch_id' => $wrongUnit, 'quantity' => 50]]])->assertUnprocessable();
        $this->asUser($this->store)->postJson($this->url("/sales-orders/{$order['id']}/dispatch"), ['lines' => [['order_line_id' => $line, 'batch_id' => $batch, 'quantity' => 60]]])->assertUnprocessable();
        $shipment = $this->asUser($this->store)->postJson($this->url("/sales-orders/{$order['id']}/dispatch"), ['vehicle' => 'UBA 123X', 'lines' => [['order_line_id' => $line, 'batch_id' => $batch, 'quantity' => 50]]])
            ->assertCreated()->assertJsonPath('data.status', 'dispatched')->assertJsonPath('data.lines.0.dispatched_quantity', 50)->json('meta.shipment');
        $this->asUser($this->store)->getJson($this->url("/shipments/{$shipment['id']}"))->assertJsonPath('data.sales_order.id', $order['id'])->assertJsonPath('data.destination', 'Plot 4, Kampala Road');

        // The customer follows it and confirms delivery: the order is delivered.
        $deliveries = $this->asUser($buyer)->getJson("{$base}/deliveries")->assertOk()->json('data');
        $this->assertSame('Eggs (tray of 30)', $deliveries[0]['lines'][0]['description']);
        $this->assertNotEmpty($deliveries[0]['lines'][0]['batch_code']);
        $this->asUser($buyer)->postJson("{$farmBase}/deliveries/{$shipment['id']}/confirm", ['received_by' => 'Grace at the till'])->assertOk()
            ->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.received_by', 'Grace at the till');
        $detail = $this->asUser($buyer)->getJson("{$farmBase}/orders/{$order['id']}")->assertOk()->assertJsonPath('data.status', 'delivered')->json('data');
        $this->assertSame(['placed', 'approved', 'invoiced', 'dispatched', 'delivered'], array_column($detail['timeline'], 'event'));
        $this->assertArrayNotHasKey('internal_note', $detail);
        $this->asUser($this->owner)->getJson($this->url("/traceability/batches/{$batch}"))->assertOk();

        // What was bought, with public traceability only once the farm approves it.
        $purchases = $this->asUser($buyer)->getJson("{$base}/purchases")->assertOk()->json('data');
        $this->assertNull($purchases[0]['public']);
        $this->asUser($this->owner)->postJson($this->url("/traceability/batches/{$batch}/approvals"), ['public_fields' => ['product', 'batch_code', 'farm']])->assertCreated();
        $public = $this->asUser($buyer)->getJson("{$base}/purchases")->json('data.0.public');
        $this->assertSame(['product', 'batch_code', 'farm'], array_keys($public));
        $this->asUser($buyer)->getJson("{$base}/dashboard")->assertOk()->assertJsonPath('data.delivered_orders', 1)
            ->assertJsonPath('data.money.0.pending_payments', 600000)->assertJsonPath('data.money.0.total_purchases', 600000);
    }

    public function test_a_customer_sees_only_its_own_orders_and_no_internal_data(): void
    {
        $a = $this->customer('Kampala Grocers');
        $b = $this->customer('Entebbe Hotel');
        [$buyer, $party] = $this->joinAsNew($this->invite($this->owner, 'customer', $a, 'buy@grocers.test'), 'Grace Grocer', 'buy@grocers.test');
        $product = $this->asUser($this->owner)->postJson($this->url('/products'), ['name' => 'Milk', 'unit' => 'l', 'list_price' => 1500, 'is_published' => true])->json('data.id');
        $theirs = $this->asUser($this->manager)->postJson($this->url('/sales-orders'), ['customer_id' => $b, 'internal_note' => 'VIP discount', 'lines' => [['product_id' => $product, 'quantity' => 10, 'unit_price' => 1000]]])
            ->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.total_amount', 10000)->json('data');

        $this->asUser($buyer)->getJson("/api/v1/customer/{$party}/orders")->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($buyer)->getJson("/api/v1/customer/{$party}/farms/{$this->farm->id}/orders/{$theirs['id']}")->assertNotFound();
        $this->asUser($buyer)->postJson("/api/v1/customer/{$party}/farms/{$this->farm->id}/orders/{$theirs['id']}/cancel", ['reason' => 'nope'])->assertNotFound();
        // A portal order cannot set its own price.
        $this->asUser($buyer)->postJson("/api/v1/customer/{$party}/farms/{$this->farm->id}/orders", ['lines' => [['product_id' => $product, 'quantity' => 2, 'unit_price' => 1]]])
            ->assertCreated()->assertJsonPath('data.lines.0.unit_price', 1500);
        // docs/04 §6 test 10: no costs, workers, stock, margins or ledger.
        foreach (['/workers', '/inventory/items', '/ledger/accounts', '/customer-invoices', '/sales-orders', '/traceability/batches'] as $path) {
            $this->asUser($buyer)->getJson($this->url($path))->assertNotFound();
        }
        $this->asUser($buyer)->getJson("/api/v1/supplier/{$party}/orders")->assertNotFound();
    }
}
