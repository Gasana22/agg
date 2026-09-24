<?php

namespace Tests\Feature\Reporting;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Farm;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceReportsTest extends TestCase
{
    private Farm $farm;

    private User $accountant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->farm = $this->farm();
        $this->owner = $this->ownerOf($this->farm);
        $this->accountant = $this->memberWithRole($this->farm, 'accountant');
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}{$path}";
    }

    public function test_profit_and_loss_cash_flow_and_cost_per_crop_and_group(): void
    {
        $agronomist = $this->memberWithRole($this->farm, 'agronomist');
        $keeper = $this->memberWithRole($this->farm, 'livestock_manager');
        $plot = $this->asUser($this->owner)->postJson($this->url('/structure/plots'), ['name' => 'Plot A', 'declared_area_ha' => 2])->json('data.id');
        $crop = $this->asUser($agronomist)->postJson($this->url('/crops'), ['global_variety_id' => DB::table('global_crop_varieties')->where('code', 'longe_5')->value('id')])->json('data.id');
        $cycle = $this->asUser($agronomist)->postJson($this->url('/crop-cycles'), ['plot_id' => $plot, 'crop_id' => $crop, 'area_ha' => 2, 'planted_on' => now()->subDays(60)->toDateString()])->assertCreated()->json('data');
        $group = $this->asUser($keeper)->postJson($this->url('/animal-groups'), ['name' => 'Layers', 'species_id' => DB::table('global_animal_species')->where('code', 'chicken')->value('id')])->assertCreated()->json('data');
        $a = collect($this->asUser($this->accountant)->getJson($this->url('/ledger/accounts'))->json('data'))->keyBy('code');
        $today = now()->toDateString();

        // Maize: 100,000 of fuel and 400,000 of grain sold at the gate. Layers: 30,000 of vet costs.
        $this->asUser($this->accountant)->postJson($this->url('/expenses'), ['account_id' => $a['5400']['id'], 'amount' => 100000, 'spent_on' => $today, 'description' => 'Diesel',
            'cost_center_type' => 'crop_cycle', 'cost_center_id' => $cycle['id'], 'paid_from_account_id' => $a['1000']['id']])->assertJsonPath('data.status', 'paid');
        $this->asUser($this->accountant)->postJson($this->url('/income'), ['account_id' => $a['4200']['id'], 'received_into_account_id' => $a['1010']['id'], 'amount' => 400000, 'received_on' => $today,
            'description' => 'Grain at the gate', 'cost_center_type' => 'crop_cycle', 'cost_center_id' => $cycle['id']])->assertCreated();
        $this->asUser($this->accountant)->postJson($this->url('/expenses'), ['account_id' => $a['5500']['id'], 'amount' => 30000, 'spent_on' => $today, 'description' => 'Vaccination visit',
            'cost_center_type' => 'animal_group', 'cost_center_id' => $group['id']])->assertJsonPath('data.status', 'approved');
        // An egg invoice due in ten days, for the forecast.
        $customer = $this->asUser($this->accountant)->postJson($this->url('/customers'), ['name' => 'Kakiri shop', 'payment_terms_days' => 10])->json('data.id');
        $inv = $this->asUser($this->accountant)->postJson($this->url('/customer-invoices'), ['customer_id' => $customer, 'lines' => [
            ['description' => 'Eggs, 40 trays', 'quantity' => 40, 'unit' => 'tray', 'unit_price' => 12000, 'account_id' => $a['4200']['id'], 'cost_center_type' => 'animal_group', 'cost_center_id' => $group['id']],
        ]])->json('data');
        $this->asUser($this->accountant)->postJson($this->url("/customer-invoices/{$inv['id']}/issue"))->assertOk();

        $pnl = $this->asUser($this->accountant)->getJson($this->url('/reports/profit-and-loss'))->assertOk()->json('data');
        $this->assertEquals(['income' => 880000, 'expenses' => 130000, 'net' => 750000], $pnl['totals']);
        $this->assertCount(12, $pnl['monthly']['labels']);
        $this->assertEquals(880000, end($pnl['monthly']['income']));
        $maize = $this->asUser($this->accountant)->getJson($this->url("/reports/profit-and-loss?cost_center_type=crop_cycle&cost_center_id={$cycle['id']}"))->json('data.totals');
        $this->assertEquals(['income' => 400000, 'expenses' => 100000, 'net' => 300000], $maize);

        $flow = $this->asUser($this->accountant)->getJson($this->url('/reports/cash-flow'))->assertOk()->json('data');
        $this->assertEquals([0, 300000], [$flow['opening'], $flow['closing']]);
        $this->assertEquals([['source' => 'income', 'amount' => 400000]], $flow['inflows']);
        $this->assertEquals(300000, $flow['forecast']['balance']);
        // Week 0 pays the approved vet expense; the egg invoice arrives in week 1.
        $this->assertEquals([30000, 270000], [$flow['forecast']['weeks'][0]['out'], $flow['forecast']['weeks'][0]['balance']]);
        $this->assertEquals([480000, 750000], [$flow['forecast']['weeks'][1]['in'], $flow['forecast']['weeks'][1]['balance']]);

        $cycles = collect($this->asUser($this->accountant)->getJson($this->url('/reports/cost-per-crop'))->assertOk()->json('data'))->keyBy('id');
        $row = $cycles[$cycle['id']];
        $this->assertEquals([100000, 400000, 300000, 50000, 20234.28], [$row['cost'], $row['revenue'], $row['margin'], $row['cost_per_ha'], $row['cost_per_acre']]);
        $groups = collect($this->asUser($this->owner)->getJson($this->url('/reports/cost-per-animal-group'))->assertOk()->json('data'))->keyBy('id');
        $this->assertEquals([30000, 480000, 450000], [$groups[$group['id']]['cost'], $groups[$group['id']]['revenue'], $groups[$group['id']]['margin']]);

        // Accountant dashboard figures agree.
        $kpis = collect($this->asUser($this->accountant)->getJson($this->url('/dashboards/accountant?period=30d'))->assertOk()->json('data.kpis'))->keyBy('key');
        $this->assertSame(['amount' => '750000.00', 'currency' => $this->farm->currency], $kpis['finance.net_profit']['value']);
        $this->assertSame('300000.00', $kpis['finance.cash_balance']['value']['amount']);
        $this->assertSame('480000.00', $kpis['finance.receivables']['value']['amount']);
        $this->assertSame('30000.00', $kpis['finance.payables']['value']['amount']);
        $this->asUser($agronomist)->getJson($this->url('/reports/profit-and-loss'))->assertForbidden();
    }
}
