<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Access\Domain\Models\FarmInvitation;
use App\Modules\Access\Domain\Models\FarmRole;
use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\Crops\Application\CropCycles;
use App\Modules\Crops\Application\CropHarvests;
use App\Modules\Crops\Application\CropObservations;
use App\Modules\Crops\Application\CropOperations;
use App\Modules\Crops\Application\CropPlans;
use App\Modules\Crops\Application\CropSetup;
use App\Modules\FarmStructure\Application\StructureService;
use App\Modules\Finance\Application\Accounts;
use App\Modules\Finance\Application\Budgets;
use App\Modules\Finance\Application\ChartOfAccounts;
use App\Modules\Finance\Application\Expenses;
use App\Modules\Finance\Application\IncomeBook;
use App\Modules\Finance\Application\PaymentDesk;
use App\Modules\Finance\Application\Payroll;
use App\Modules\Finance\Domain\Models\LedgerAccount;
use App\Modules\Inventory\Application\Items;
use App\Modules\Inventory\Application\StockDesk;
use App\Modules\Livestock\Application\AnimalRecords;
use App\Modules\Livestock\Application\AnimalSales;
use App\Modules\Livestock\Application\Breedings;
use App\Modules\Livestock\Application\Herd;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Procurement\Application\Purchasing;
use App\Modules\Procurement\Application\Receiving;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Sales\Application\Invoicing;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Models\TraceEvent;
use App\Modules\Workforce\Application\Activities;
use App\Modules\Workforce\Application\LeaveDesk;
use App\Modules\Workforce\Application\Workers;
use App\Modules\Workforce\Domain\Models\Attendance;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
        // The sweep calls every route; rate limits are tested elsewhere.
        foreach (['api', 'sync'] as $limiter) {
            RateLimiter::for($limiter, fn () => Limit::none());
        }

        $this->seed(CatalogSeeder::class);
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
        $this->victimRecords += $this->victimCropRecords($this->victimRecords['plot']);
        $this->victimRecords += $this->victimLivestockRecords();
        $this->victimRecords += $this->victimWorkforceRecords();
        $this->victimRecords += $this->victimStockRecords($this->victimRecords['location']);
        $this->victimRecords += $this->victimFinanceRecords($this->victimRecords['location'], $this->victimRecords['order']);
    }

    /** One of each finance and sales document, as the victim's owner. */
    private function victimFinanceRecords(string $storeId, string $orderId): array
    {
        $owner = FarmUser::where('farm_id', $this->victim->id)->where('is_owner', true)->firstOrFail();
        $this->actingAs($owner->user, 'api');

        return $this->app->make(TenantContext::class)->run($this->victim, function () use ($storeId, $orderId) {
            $this->app->make(ChartOfAccounts::class)->ensure();
            $code = fn (string $c) => LedgerAccount::where('code', $c)->value('id');
            $account = $this->app->make(Accounts::class)->create(['code' => '5010', 'name' => 'Victim costs', 'type' => 'expense']);
            $expense = $this->app->make(Expenses::class)->create(['account_id' => $account->id, 'amount' => 1000, 'spent_on' => now()->toDateString(), 'description' => 'Victim fuel']);
            $income = $this->app->make(IncomeBook::class)->record(['account_id' => $code('4100'), 'received_into_account_id' => $code('1000'), 'amount' => 500, 'received_on' => now()->toDateString(), 'description' => 'Victim manure']);
            $payment = $this->app->make(PaymentDesk::class)->record(['payable_type' => 'expense', 'payable_id' => $expense->id, 'amount' => 100, 'method' => 'cash', 'account_id' => $code('1000')]);
            $worker = $this->app->make(Workers::class)->create(['full_name' => 'Victim Casual', 'employment_type' => 'casual', 'daily_rate' => 10000]);
            Attendance::create(['worker_id' => $worker->id, 'work_date' => now()->subDays(2)->toDateString(), 'check_in_at' => now()->subDays(2), 'source' => 'manual']);
            $run = $this->app->make(Payroll::class)->prepare(['period_start' => now()->subDays(3)->toDateString(), 'period_end' => now()->subDay()->toDateString()]);
            $budget = $this->app->make(Budgets::class)->create(['name' => 'Victim budget', 'period_start' => now()->startOfYear()->toDateString(), 'period_end' => now()->endOfYear()->toDateString(),
                'lines' => [['account_id' => $account->id, 'amount' => 5000]]]);
            $sales = $this->app->make(Invoicing::class);
            $customer = $sales->createCustomer(['name' => 'Victim buyer']);
            $invoice = $sales->create(['customer_id' => $customer->id, 'lines' => [['description' => 'Eggs', 'quantity' => 1, 'unit_price' => 100, 'account_id' => $code('4100')]]]);
            $order = PurchaseOrder::with('lines')->findOrFail($orderId);
            $this->app->make(Purchasing::class)->approveOrder($order);
            $this->app->make(Receiving::class)->receive($order->refresh(), ['location_id' => $storeId, 'lines' => [['order_line_id' => $order->lines[0]->id, 'quantity' => 5]]]);
            $supplierInvoice = $this->app->make(Receiving::class)->invoice($order->refresh(), ['invoice_number' => 'V-1', 'invoice_date' => now()->toDateString(),
                'lines' => [['order_line_id' => $order->lines[0]->id, 'quantity' => 5, 'unit_price' => 100]]]);

            return [
                'ledgerAccount' => $account->id, 'expense' => $expense->id, 'income' => $income->id, 'payment' => $payment->id,
                'payrollRun' => $run->id, 'payrollLine' => $run->lines()->value('id'), 'budget' => $budget->id,
                'customer' => $customer->id, 'customerInvoice' => $invoice->id, 'supplierInvoice' => $supplierInvoice->id,
            ];
        }, $owner);
    }

    /** Stock, a count, a request, a supplier, a purchase request and order, and a ledger entry, as the victim's owner. */
    private function victimStockRecords(string $storeId): array
    {
        $owner = FarmUser::where('farm_id', $this->victim->id)->where('is_owner', true)->firstOrFail();
        $this->actingAs($owner->user, 'api');

        return $this->app->make(TenantContext::class)->run($this->victim, function () use ($storeId) {
            $item = $this->app->make(Items::class)->create(['name' => 'Victim feed', 'unit' => 'kg', 'tracks_lots' => false,
                'category_id' => DB::table('global_inventory_categories')->where('code', 'animal_feed')->value('id')]);
            $desk = $this->app->make(StockDesk::class);
            $movement = $desk->stockIn(['item_id' => $item->id, 'location_id' => $storeId, 'quantity' => 10, 'unit_cost' => 100]);
            $buy = $this->app->make(Purchasing::class);
            $supplier = $buy->createSupplier(['name' => 'Victim supplier']);

            return [
                'item' => $item->id,
                'adjustment' => $desk->proposeAdjustment(['location_id' => $storeId, 'reason' => 'Count', 'lines' => [['item_id' => $item->id, 'counted_quantity' => 9]]])->id,
                'inventoryRequest' => $desk->request(['lines' => [['item_id' => $item->id, 'quantity' => 1]]])->id,
                'supplier' => $supplier->id,
                'purchaseRequest' => $buy->request(['lines' => [['item_id' => $item->id, 'quantity' => 5]]])->id,
                'order' => $buy->createOrder(['supplier_id' => $supplier->id, 'lines' => [['item_id' => $item->id, 'quantity' => 5, 'unit_price' => 100]]])->id,
                'entry' => $movement->ledger_entry_id,
            ];
        }, $owner);
    }

    /** One of each livestock record, created as the victim's owner. */
    private function victimLivestockRecords(): array
    {
        $owner = FarmUser::where('farm_id', $this->victim->id)->where('is_owner', true)->firstOrFail();
        $this->actingAs($owner->user, 'api');
        $cattle = DB::table('global_animal_species')->where('code', 'cattle')->value('id');

        return $this->app->make(TenantContext::class)->run($this->victim, function () use ($cattle) {
            $herd = $this->app->make(Herd::class);
            $records = $this->app->make(AnimalRecords::class);
            $group = $herd->createGroup(['name' => 'Victim herd', 'species_id' => $cattle]);
            $cow = $herd->register(['species_id' => $cattle, 'sex' => 'female', 'origin' => 'purchased', 'group_id' => $group->id]);
            $bull = $herd->register(['species_id' => $cattle, 'sex' => 'male', 'origin' => 'purchased']);

            return [
                'group' => $group->id,
                'animal' => $cow->id,
                'health_record' => $records->health(['animal_id' => $cow->id, 'kind' => 'checkup', 'given_on' => now()->toDateString()])->id,
                'feeding' => $records->feeding(['group_id' => $group->id, 'fed_on' => now()->toDateString(), 'feed_name' => 'Hay', 'quantity' => 20, 'unit' => 'kg'])->id,
                'weight' => $records->weight(['animal_id' => $cow->id, 'weighed_on' => now()->toDateString(), 'weight_kg' => 420])->id,
                'production' => $records->production(['animal_id' => $cow->id, 'product' => 'milk', 'produced_on' => now()->toDateString(), 'quantity' => 9, 'unit' => 'l'])->id,
                'breeding' => $this->app->make(Breedings::class)->serve(['dam_id' => $cow->id, 'sire_id' => $bull->id, 'method' => 'natural', 'served_on' => now()->toDateString()])->id,
                'sale' => $this->app->make(AnimalSales::class)->request(['animal_id' => $bull->id])->id,
            ];
        }, $owner);
    }

    /** One of each workforce record and a stored photo, created as the victim's owner (also their worker). */
    private function victimWorkforceRecords(): array
    {
        $owner = FarmUser::where('farm_id', $this->victim->id)->where('is_owner', true)->firstOrFail();
        $this->actingAs($owner->user, 'api');

        return $this->app->make(TenantContext::class)->run($this->victim, function () use ($owner) {
            $worker = $this->app->make(Workers::class)->create(['full_name' => 'Victim Worker', 'employment_type' => 'casual', 'farm_user_id' => $owner->id]);
            $activity = $this->app->make(Activities::class)->create([
                'activity_type_id' => DB::table('global_activity_types')->where('code', 'general_labour')->value('id'),
                'subject_type' => 'general', 'worker_ids' => [$worker->id],
            ]);
            $media = Media::create(['sha256' => str_repeat('b', 64), 'mime' => 'image/png', 'size_bytes' => 10, 'disk' => 'local', 'path' => 'victim.png', 'uploaded_by' => $owner->user_id]);
            $attendance = Attendance::create(['worker_id' => $worker->id, 'work_date' => now()->toDateString(), 'check_in_at' => now()->subHour(), 'source' => 'manual']);
            $leave = $this->app->make(LeaveDesk::class)->request(['kind' => 'annual', 'from_on' => now()->addMonth()->toDateString(), 'to_on' => now()->addMonth()->toDateString()]);

            return [
                'worker' => $worker->id,
                'activity' => $activity->id,
                'task' => $activity->tasks()->value('id'),
                'attendance' => $attendance->id,
                'leave' => $leave->id,
                'media' => $media->id,
            ];
        }, $owner);
    }

    /** One of each crop record, created as the victim's owner. */
    private function victimCropRecords(string $plotId): array
    {
        $owner = FarmUser::where('farm_id', $this->victim->id)->where('is_owner', true)->firstOrFail();
        $this->actingAs($owner->user, 'api');

        return $this->app->make(TenantContext::class)->run($this->victim, function () use ($plotId) {
            $crop = $this->app->make(CropSetup::class)->addCrop(['name' => 'Victim maize']);
            $season = $this->app->make(CropSetup::class)->addSeason(['name' => 'Victim season', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30']);
            $plan = $this->app->make(CropPlans::class)->create(['name' => 'Victim plan', 'season_id' => $season->id, 'crop_id' => $crop->id, 'planned_area_ha' => 1]);
            [$cycle] = $this->app->make(CropCycles::class)->start(['plot_id' => $plotId, 'crop_id' => $crop->id, 'area_ha' => 1]);
            $operation = $this->app->make(CropOperations::class)->record($cycle, ['type' => 'weeding']);
            $observation = $this->app->make(CropObservations::class)->report($cycle, ['kind' => 'pest', 'severity' => 'low', 'title' => 'Aphids']);
            $harvest = $this->app->make(CropHarvests::class)->record($cycle, ['harvested_on' => now()->toDateString(), 'quantity' => 10, 'unit' => 'kg']);

            return [
                'crop' => $crop->id,
                'season' => $season->id,
                'crop_plan' => $plan->id,
                'cycle' => $cycle->id,
                'operation' => $operation->id,
                'observation' => $observation->id,
                'harvest' => $harvest->id,
            ];
        }, $owner);
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
            'crop_rows' => collect(['crops', 'crop_seasons', 'crop_plans', 'crop_cycles', 'crop_operations', 'crop_observations', 'crop_harvests'])
                ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all(),
            'livestock_rows' => collect(['animal_groups', 'animals', 'animal_health_records', 'animal_feedings', 'animal_weights', 'animal_production_records', 'animal_movements', 'animal_record_voids', 'animal_breedings', 'animal_sale_requests'])
                ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all(),
            'livestock_versions' => DB::table('animals')->sum('version') + DB::table('animal_breedings')->sum('version') + DB::table('animal_sale_requests')->sum('version') + DB::table('animal_groups')->sum('version'),
            'crop_versions' => DB::table('crop_cycles')->sum('version') + DB::table('crop_plans')->sum('version') + DB::table('crop_observations')->sum('version') + DB::table('crop_operations')->sum('version'),
            'structure' => DB::table('farm_blocks')->whereNull('deleted_at')->count() + DB::table('farm_sections')->whereNull('deleted_at')->count()
                + DB::table('farm_plots')->whereNull('deleted_at')->count() + DB::table('farm_locations')->whereNull('deleted_at')->count(),
            'structure_versions' => DB::table('farm_plots')->sum('version'),
            'finance_rows' => collect(['ledger_accounts', 'expenses', 'income_records', 'payments', 'payroll_runs', 'payroll_lines', 'budgets', 'budget_lines', 'customers', 'customer_invoices', 'customer_invoice_lines'])
                ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all(),
            'finance_versions' => DB::table('expenses')->sum('version') + DB::table('income_records')->sum('version') + DB::table('payroll_runs')->sum('version')
                + DB::table('budgets')->sum('version') + DB::table('customers')->sum('version') + DB::table('customer_invoices')->sum('version') + DB::table('supplier_invoices')->sum('version'),
            'finance_statuses' => DB::table('expenses')->orderBy('id')->pluck('status')->merge(DB::table('payments')->orderBy('id')->pluck('status'))->merge(DB::table('customer_invoices')->orderBy('id')->pluck('status'))
                ->merge(DB::table('payroll_runs')->orderBy('id')->pluck('status'))->merge(DB::table('supplier_invoices')->orderBy('id')->pluck('status'))->all(),
            'stock_rows' => collect(['inventory_items', 'stock_lots', 'stock_balances', 'stock_movements', 'stock_transfers', 'stock_adjustments', 'inventory_requests', 'suppliers', 'purchase_requests', 'purchase_orders', 'purchase_order_lines', 'deliveries', 'supplier_invoices', 'ledger_entries', 'ledger_lines'])
                ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all(),
            'stock_versions' => DB::table('inventory_items')->sum('version') + DB::table('stock_adjustments')->sum('version') + DB::table('inventory_requests')->sum('version')
                + DB::table('suppliers')->sum('version') + DB::table('purchase_requests')->sum('version') + DB::table('purchase_orders')->sum('version'),
            'stock_quantity' => (string) DB::table('stock_balances')->sum('quantity'),
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

        // Assigning another farm's worker, or syncing a step on another farm's task.
        $farm = "/api/v1/farms/{$this->attackerFarm->id}";
        $this->asUser($attacker)->postJson("{$farm}/activities", [
            'activity_type_id' => DB::table('global_activity_types')->where('code', 'general_labour')->value('id'),
            'worker_ids' => [$this->victimRecords['worker']],
        ])->assertStatus(422)->assertJsonValidationErrors('worker_ids.0');
        $this->asUser($attacker)->postJson("{$farm}/workers", ['full_name' => 'Borrowed', 'employment_type' => 'casual', 'farm_user_id' => FarmUser::where('farm_id', $this->victim->id)->value('id')])
            ->assertStatus(422)->assertJsonValidationErrors('farm_user_id');
        $result = $this->asUser($attacker)->postJson("{$farm}/sync/push", ['mutations' => [[
            'mutation_id' => (string) Str::uuid7(), 'entity' => 'worker_task_logs', 'op' => 'insert', 'id' => (string) Str::uuid7(),
            'data' => ['task_id' => $this->victimRecords['task'], 'event' => 'start'],
        ]]])->assertOk()->json('data.results.0');
        $this->assertSame('rejected', $result['status']);

        // Issuing, requesting or buying another farm's item; receiving into another farm's store; ordering from its supplier.
        $store = $this->asUser($attacker)->postJson("{$farm}/structure/locations", ['name' => 'Attacker store', 'kind' => 'store'])->assertCreated()->json('data.id');
        $this->asUser($attacker)->postJson("{$farm}/inventory/issues", ['item_id' => $this->victimRecords['item'], 'location_id' => $store, 'quantity' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('item_id');
        $this->asUser($attacker)->postJson("{$farm}/inventory/stock-in", ['item_id' => $this->victimRecords['item'], 'location_id' => $this->victimRecords['location'], 'quantity' => 1])
            ->assertStatus(422);
        $this->asUser($attacker)->postJson("{$farm}/inventory/requests", ['lines' => [['item_id' => $this->victimRecords['item'], 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.item_id');
        // Paying another farm's invoice, spending from its accounts, invoicing its customer.
        $this->asUser($attacker)->postJson("{$farm}/payments", ['payable_type' => 'customer_invoice', 'payable_id' => $this->victimRecords['customerInvoice'], 'amount' => 1, 'method' => 'cash',
            'account_id' => $this->victimRecords['ledgerAccount']])->assertStatus(422);
        $this->asUser($attacker)->postJson("{$farm}/expenses", ['account_id' => $this->victimRecords['ledgerAccount'], 'amount' => 1, 'spent_on' => now()->toDateString(), 'description' => 'Borrowed account'])
            ->assertStatus(422)->assertJsonValidationErrors('account_id');
        $this->asUser($attacker)->postJson("{$farm}/customer-invoices", ['customer_id' => $this->victimRecords['customer'], 'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1, 'account_id' => $this->victimRecords['ledgerAccount']]]])
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $this->asUser($attacker)->postJson("{$farm}/purchase-orders", ['supplier_id' => $this->victimRecords['supplier'], 'lines' => [['item_id' => $this->victimRecords['item'], 'quantity' => 1, 'unit_price' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('supplier_id');
    }

    public function test_random_farm_ids_are_indistinguishable_from_forbidden_ones(): void
    {
        $attacker = $this->ownerOf($this->attackerFarm);

        $this->assertProblem($this->asUser($attacker)->getJson('/api/v1/farms/'.Str::uuid7()), 404, 'not_found');
        $this->assertProblem($this->asUser($attacker)->getJson('/api/v1/farms/not-a-uuid'), 404, 'not_found');
        $this->assertProblem($this->asUser($attacker)->getJson("/api/v1/farms/{$this->victim->id}"), 404, 'not_found');
    }
}
