<?php

namespace Tests\Feature\Sales;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Farm;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesTest extends TestCase
{
    private Farm $farm;

    private User $owner;

    private User $accountant;

    private User $keeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->farm = $this->farm();
        $this->owner = $this->ownerOf($this->farm);
        $this->accountant = $this->memberWithRole($this->farm, 'accountant');
        $this->keeper = $this->memberWithRole($this->farm, 'livestock_manager');
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}{$path}";
    }

    /** @return array<string, array> by code */
    private function accounts(): array
    {
        $tb = $this->asUser($this->accountant)->getJson($this->url('/ledger/accounts'))->assertOk()->json();
        $this->assertEquals($tb['meta']['total_debit'], $tb['meta']['total_credit']);

        return collect($tb['data'])->keyBy('code')->all();
    }

    /** A steer sold for 1,750,000 through the livestock sale flow. */
    private function soldSteer(): array
    {
        $cattle = DB::table('global_animal_species')->where('code', 'cattle')->value('id');
        $steer = $this->asUser($this->keeper)->postJson($this->url('/animals'), ['species_id' => $cattle, 'sex' => 'male', 'origin' => 'purchased', 'tag_number' => 'UG-2001'])->assertCreated()->json('data');
        $sale = $this->asUser($this->keeper)->postJson($this->url('/animal-sales'), ['animal_id' => $steer['id'], 'reason' => 'Finished', 'buyer' => 'Kampala butchery'])->assertCreated()->json('data');
        $this->asUser($this->owner)->postJson($this->url("/animal-sales/{$sale['id']}/approve"))->assertOk();
        $this->asUser($this->owner)->postJson($this->url("/animal-sales/{$sale['id']}/complete"), ['sold_on' => now()->toDateString(), 'sale_price' => 1750000])->assertOk();

        return [$steer, $sale];
    }

    public function test_invoice_a_livestock_sale_issue_collect_and_void(): void
    {
        [$steer, $sale] = $this->soldSteer();
        $a = $this->accounts();
        $customer = $this->asUser($this->accountant)->postJson($this->url('/customers'), ['name' => 'Kampala Prime Butchery', 'phone' => '+256 772 000111', 'payment_terms_days' => 14])
            ->assertCreated()->assertJsonPath('data.code', 'CUS-001')->json('data');
        $this->asUser($this->keeper)->postJson($this->url('/customers'), ['name' => 'x'])->assertForbidden();

        $billable = $this->asUser($this->accountant)->getJson($this->url('/customer-invoices/billable/livestock-sales'))->assertOk()->json('data');
        $this->assertSame([$sale['id']], array_column($billable, 'id'));

        // A draft: the steer, and transport charged on.
        $draft = $this->asUser($this->accountant)->postJson($this->url('/customer-invoices'), ['customer_id' => $customer['id'], 'lines' => [
            ['animal_sale_id' => $sale['id'], 'quantity' => 1, 'unit' => 'head', 'unit_price' => 1750000, 'account_id' => $a['4200']['id']],
            ['description' => 'Transport to Kampala', 'quantity' => 1, 'unit_price' => 50000, 'account_id' => $a['4100']['id']],
        ]])->assertCreated()->assertJsonPath('data.code', 'INV-0001')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.amount', 1800000)
            ->assertJsonPath('data.lines.0.cost_center.type', 'animal')->assertJsonPath('data.lines.0.cost_center.id', $steer['id'])->json('data');
        $this->assertEquals(0, (float) $this->accounts()['1200']['balance']);   // drafts post nothing
        $this->asUser($this->accountant)->postJson($this->url('/customer-invoices'), ['customer_id' => $customer['id'], 'lines' => [
            ['animal_sale_id' => $sale['id'], 'quantity' => 1, 'unit_price' => 1, 'account_id' => $a['4200']['id']],
        ]])->assertStatus(422)->assertJsonValidationErrors('lines.0.animal_sale_id');   // already on INV-0001

        // Issue: receivables and income; due by the customer's terms.
        $issued = $this->asUser($this->accountant)->postJson($this->url("/customer-invoices/{$draft['id']}/issue"))->assertOk()
            ->assertJsonPath('data.status', 'issued')->assertJsonPath('data.due_on', now()->addDays(14)->toDateString())->json('data');
        $this->asUser($this->accountant)->patchJson($this->url("/customer-invoices/{$draft['id']}"), ['notes' => 'x'])->assertStatus(409);
        $b = $this->accounts();
        $this->assertEquals([1800000, 1750000, 50000], [(float) $b['1200']['balance'], (float) $b['4200']['balance'], (float) $b['4100']['balance']]);

        // Two instalments by mobile money.
        $pay = fn (float $amount) => $this->asUser($this->accountant)->postJson($this->url('/payments'), ['payable_type' => 'customer_invoice', 'payable_id' => $issued['id'], 'amount' => $amount, 'method' => 'mobile_money', 'account_id' => $a['1010']['id']]);
        $first = $pay(1000000)->assertCreated()->assertJsonPath('data.direction', 'in')->assertJsonPath('data.party', 'Kampala Prime Butchery')->json('data');
        $pay(900000)->assertStatus(422);
        $pay(800000)->assertCreated();
        $this->asUser($this->accountant)->getJson($this->url("/customer-invoices/{$draft['id']}"))->assertJsonPath('data.status', 'paid')->assertJsonPath('data.paid_amount', 1800000);
        $b = $this->accounts();
        $this->assertEquals([0, 1800000], [(float) $b['1200']['balance'], (float) $b['1010']['balance']]);

        // A paid invoice cannot be voided; take the payments back first.
        $this->asUser($this->accountant)->postJson($this->url("/customer-invoices/{$draft['id']}/void"), ['reason' => 'Wrong customer'])->assertStatus(409);
        $this->asUser($this->accountant)->postJson($this->url("/payments/{$first['id']}/void"), ['reason' => 'Bounced'])->assertOk();
        $this->asUser($this->accountant)->getJson($this->url("/customer-invoices/{$draft['id']}"))->assertJsonPath('data.status', 'issued')->assertJsonPath('data.paid_amount', 800000);

        // Overdue listing, and the billable list no longer offers the sale.
        $this->assertCount(0, $this->asUser($this->accountant)->getJson($this->url('/customer-invoices/billable/livestock-sales'))->json('data'));
        $this->asUser($this->accountant)->getJson($this->url('/customer-invoices?filter[overdue]=1'))->assertJsonCount(0, 'data');
    }

    public function test_voided_invoice_frees_the_sale_and_reverses_the_books(): void
    {
        [, $sale] = $this->soldSteer();
        $a = $this->accounts();
        $customer = $this->asUser($this->owner)->postJson($this->url('/customers'), ['name' => 'Wakiso Traders'])->json('data');
        $inv = $this->asUser($this->accountant)->postJson($this->url('/customer-invoices'), ['customer_id' => $customer['id'], 'lines' => [
            ['animal_sale_id' => $sale['id'], 'quantity' => 1, 'unit_price' => 1750000, 'account_id' => $a['4200']['id']],
        ]])->json('data');
        $this->asUser($this->accountant)->postJson($this->url("/customer-invoices/{$inv['id']}/issue"))->assertOk();
        $this->asUser($this->accountant)->postJson($this->url("/customer-invoices/{$inv['id']}/void"), ['reason' => 'Wrong customer'])->assertOk()->assertJsonPath('data.status', 'void');
        $b = $this->accounts();
        $this->assertEquals([0, 0], [(float) $b['1200']['balance'], (float) $b['4200']['balance']]);
        $this->assertCount(1, $this->asUser($this->accountant)->getJson($this->url('/customer-invoices/billable/livestock-sales'))->json('data'));
        $this->asUser($this->accountant)->postJson($this->url('/payments'), ['payable_type' => 'customer_invoice', 'payable_id' => $inv['id'], 'amount' => 1, 'method' => 'cash', 'account_id' => $a['1000']['id']])
            ->assertStatus(409)->assertJsonPath('code', 'nothing_to_pay');

        // The livestock manager neither sees invoices nor records payments.
        $this->asUser($this->keeper)->getJson($this->url('/customer-invoices'))->assertForbidden();
        $this->asUser($this->keeper)->postJson($this->url('/payments'), [])->assertForbidden();
    }
}
