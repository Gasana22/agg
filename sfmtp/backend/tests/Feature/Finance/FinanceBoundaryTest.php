<?php

namespace Tests\Feature\Finance;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\Tenancy\Domain\Models\Farm;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 8 gate (docs/04 §6 #2, #5, #6): the field worker, and the crop,
 * livestock and store leads, get 403 on every finance, sales-invoice and
 * financial-report route, with real record ids of their own farm.
 */
class FinanceBoundaryTest extends TestCase
{
    private const FINANCE = '#^api/v1/farms/\{farm\}/(ledger|expenses|income|payments|payroll-runs|budgets|customers|customer-invoices|supplier-invoices|reports)(/|$)#';

    private Farm $farm;

    /** @var array<string, string> route parameter => a real record id */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['api', 'auth'] as $limiter) {
            RateLimiter::for($limiter, fn () => Limit::none());
        }
        $this->seed(CatalogSeeder::class);
        $this->farm = $this->farm();
        $accountant = $this->memberWithRole($this->farm, 'accountant');
        $owner = $this->ownerOf($this->farm);
        $url = fn (string $p) => "/api/v1/farms/{$this->farm->id}{$p}";
        $a = collect($this->asUser($accountant)->getJson($url('/ledger/accounts'))->json('data'))->keyBy('code');

        $expense = $this->asUser($accountant)->postJson($url('/expenses'), ['account_id' => $a['5400']['id'], 'amount' => 1000, 'spent_on' => now()->toDateString(), 'description' => 'Fuel'])->json('data');
        $income = $this->asUser($accountant)->postJson($url('/income'), ['account_id' => $a['4100']['id'], 'received_into_account_id' => $a['1000']['id'], 'amount' => 500, 'received_on' => now()->toDateString(), 'description' => 'Manure'])->json('data');
        $payment = $this->asUser($accountant)->postJson($url('/payments'), ['payable_type' => 'expense', 'payable_id' => $expense['id'], 'amount' => 100, 'method' => 'cash', 'account_id' => $a['1000']['id']])->json('data');
        $worker = $this->asUser($owner)->postJson($url('/workers'), ['full_name' => 'Okello', 'employment_type' => 'casual', 'daily_rate' => 10000])->json('data');
        $this->inFarm($this->farm, fn () => DB::table('worker_attendance')->insert(['id' => (string) Str::uuid7(), 'farm_id' => $this->farm->id, 'worker_id' => $worker['id'],
            'work_date' => CarbonImmutable::yesterday()->toDateString(), 'check_in_at' => now()->subDay(), 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]));
        $run = $this->asUser($accountant)->postJson($url('/payroll-runs'), ['period_start' => now()->subDays(3)->toDateString(), 'period_end' => now()->subDay()->toDateString()])->json('data');
        $budget = $this->asUser($accountant)->postJson($url('/budgets'), ['name' => 'Year', 'period_start' => now()->startOfYear()->toDateString(), 'period_end' => now()->endOfYear()->toDateString(),
            'lines' => [['account_id' => $a['5400']['id'], 'amount' => 100000]]])->json('data');
        $customer = $this->asUser($accountant)->postJson($url('/customers'), ['name' => 'Buyer'])->json('data');
        $invoice = $this->asUser($accountant)->postJson($url('/customer-invoices'), ['customer_id' => $customer['id'], 'lines' => [['description' => 'Eggs', 'quantity' => 10, 'unit_price' => 500, 'account_id' => $a['4100']['id']]]])->json('data');

        // A supplier invoice, through the purchasing flow.
        $store = $this->asUser($owner)->postJson($url('/structure/locations'), ['code' => 'ST', 'name' => 'Store', 'kind' => 'store'])->json('data.id');
        $item = $this->asUser($owner)->postJson($url('/inventory/items'), ['name' => 'Diesel', 'unit' => 'l', 'tracks_lots' => false, 'category_id' => DB::table('global_inventory_categories')->where('code', 'fuel')->value('id')])->json('data.id');
        $supplier = $this->asUser($owner)->postJson($url('/suppliers'), ['name' => 'Shell'])->json('data.id');
        $po = $this->asUser($owner)->postJson($url('/purchase-orders'), ['supplier_id' => $supplier, 'lines' => [['item_id' => $item, 'quantity' => 10, 'unit_price' => 5000]]])->json('data');
        $this->asUser($owner)->postJson($url("/purchase-orders/{$po['id']}/approve"));
        $this->asUser($owner)->postJson($url("/purchase-orders/{$po['id']}/deliveries"), ['location_id' => $store, 'lines' => [['order_line_id' => $po['lines'][0]['id'], 'quantity' => 10]]])->assertCreated();
        $sinv = $this->asUser($owner)->postJson($url("/purchase-orders/{$po['id']}/invoices"), ['invoice_number' => 'S-1', 'invoice_date' => now()->toDateString(), 'lines' => [['order_line_id' => $po['lines'][0]['id'], 'quantity' => 10, 'unit_price' => 5000]]])
            ->assertCreated()->json('data');

        $this->ids = [
            'entry' => $expense['ledger_entry_id'], 'ledgerAccount' => $a['5400']['id'], 'expense' => $expense['id'], 'income' => $income['id'],
            'payment' => $payment['id'], 'payrollRun' => $run['id'], 'payrollLine' => $run['lines'][0]['id'], 'budget' => $budget['id'],
            'customer' => $customer['id'], 'customerInvoice' => $invoice['id'],
            'supplierInvoice' => $sinv['id'],
        ];
    }

    /** @return array<int, Route> */
    private function routes(): array
    {
        return array_values(array_filter(Router::getRoutes()->getRoutes(), fn (Route $r) => preg_match(self::FINANCE, $r->uri())));
    }

    public function test_workers_and_operational_leads_are_refused_every_finance_route(): void
    {
        $routes = $this->routes();
        $this->assertGreaterThan(40, count($routes));
        foreach (['field_worker', 'agronomist', 'livestock_manager', 'store_manager'] as $role) {
            $user = $this->memberWithRole($this->farm, $role);
            foreach ($routes as $route) {
                $uri = preg_replace_callback('/\{(\w+)\}/', fn ($m) => $m[1] === 'farm' ? $this->farm->id : ($this->ids[$m[1]] ?? 'missing'), $route->uri());
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $status = $this->asUser($user)->json($method, "/{$uri}", ['reason' => 'x x x', 'note' => 'x x x'])->status();
                    $this->assertContains($status, [403], "{$role}: {$method} {$route->uri()} answered {$status}");
                }
            }
        }
    }

    public function test_the_ledger_balances_and_nothing_posted_can_change(): void
    {
        $this->inFarm($this->farm, function () {
            $sums = DB::table('ledger_lines')->selectRaw('SUM(debit) AS d, SUM(credit) AS c')->first();
            $this->assertEquals($sums->d, $sums->c);
            // Every entry balances on its own.
            $off = DB::table('ledger_lines')->groupBy('entry_id')->havingRaw('SUM(debit) <> SUM(credit)')->pluck('entry_id');
            $this->assertCount(0, $off);
        });
        $this->expectException(QueryException::class);
        $this->inFarm($this->farm, fn () => DB::table('ledger_entries')->where('id', $this->ids['entry'])->delete());
    }
}
