<?php

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\FarmStructure\Application\StructureService;
use App\Modules\Finance\Application\ChartOfAccounts;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Application\Items;
use App\Modules\Inventory\Application\StockService;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Tenancy\Application\FarmService;
use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 gate: parallel issues never make stock negative. Real processes
 * (pcntl_fork), each with its own database connection, issue from the same
 * balance at the same moment. The data is committed (no wrapping
 * transaction), so this class truncates instead of rolling back.
 */
class StockConcurrencyTest extends BaseTestCase
{
    use DatabaseTruncation;

    /** Filled by migrations; kept for the other tests. */
    protected $exceptTables = ['migrations', 'platform_roles', 'subscription_plans'];

    private const WORKERS = 10;

    /** Leave no committed rows behind for the tests that run next. */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_parallel_issues_never_make_stock_negative(): void
    {
        if (! function_exists('pcntl_fork')) {
            // The Phase 7 gate: never skipped silently in CI.
            getenv('CI') ? $this->fail('The concurrency test needs the pcntl extension.') : $this->markTestSkipped('Needs the pcntl extension.');
        }
        $this->seed(CatalogSeeder::class);
        $owner = User::factory()->create(['mfa_enabled_at' => now()]);
        $farm = $this->app->make(FarmService::class)->create($owner, ['name' => 'Race Farm']);
        $farm->forceFill(['status' => FarmStatus::Active])->save();
        $membership = FarmUser::where('farm_id', $farm->id)->where('user_id', $owner->id)->firstOrFail();
        Auth::setUser($owner);

        [$itemId, $storeId, $lotItemId] = $this->app->make(TenantContext::class)->run($farm, function () {
            [$store] = $this->app->make(StructureService::class)->create('location', ['code' => 'MAIN', 'name' => 'Main store', 'kind' => 'store']);
            $category = DB::table('global_inventory_categories')->where('code', 'animal_feed')->value('id');
            $feed = $this->app->make(Items::class)->create(['name' => 'Dairy meal', 'category_id' => $category, 'unit' => 'kg', 'tracks_lots' => false]);
            $seed = $this->app->make(Items::class)->create(['name' => 'Bean seed', 'category_id' => $category, 'unit' => 'kg', 'tracks_lots' => true]);
            $stock = $this->app->make(StockService::class);
            $stock->receive($feed, $store->id, 100, 1500, 'opening', 'opening_stock', null, ChartOfAccounts::OPENING_BALANCES);
            $stock->receive($seed, $store->id, 60, 4000, 'opening', 'opening_stock', null, ChartOfAccounts::OPENING_BALANCES, ['lot_number' => 'A']);
            $stock->receive($seed, $store->id, 40, 4200, 'opening', 'opening_stock', null, ChartOfAccounts::OPENING_BALANCES, ['lot_number' => 'B']);

            return [$feed->id, $store->id, $seed->id];
        }, $membership);

        // Untracked stock: 10 × 15 kg from 100 kg → exactly 6 succeed.
        $results = $this->race($farm, $membership, $owner, $itemId, $storeId, 15);
        $this->assertSame(['ok' => 6, 'insufficient' => 4, 'error' => 0], $results);
        // Lots: 10 × 12 kg from two lots of 60 + 40 kg → 8 succeed, across both lots.
        $results = $this->race($farm, $membership, $owner, $lotItemId, $storeId, 12);
        $this->assertSame(['ok' => 8, 'insufficient' => 2, 'error' => 0], $results);

        $this->app->make(TenantContext::class)->run($farm, function () use ($itemId, $lotItemId) {
            $this->assertEquals(10, DB::table('stock_balances')->where('item_id', $itemId)->sum('quantity'));
            $this->assertEquals(4, DB::table('stock_balances')->where('item_id', $lotItemId)->sum('quantity'));
            $this->assertSame(0, DB::table('stock_balances')->where('quantity', '<', 0)->count());
            $this->assertSame(6 + 1, DB::table('stock_movements')->where('item_id', $itemId)->count());
            // The ledger still balances, and every issue was posted once.
            $sums = DB::table('ledger_lines')->selectRaw('SUM(debit) AS d, SUM(credit) AS c')->first();
            $this->assertEquals($sums->d, $sums->c);
            $this->assertSame(3 + 6 + 8, DB::table('ledger_entries')->count());
            $this->assertEquals(0, DB::table('stock_balances')->where('item_id', $itemId)->sum('value') - 10 * 1500);
        });
    }

    /** @return array{ok:int, insufficient:int, error:int} */
    private function race(Farm $farm, FarmUser $membership, User $owner, string $itemId, string $storeId, int $quantity): array
    {
        $start = microtime(true) + 1.0;
        $pids = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $code = 2;
                try {
                    DB::purge();
                    DB::reconnect();
                    Auth::setUser($owner);
                    while (microtime(true) < $start) {
                        usleep(500);
                    }
                    $code = $this->app->make(TenantContext::class)->run($farm, function () use ($itemId, $storeId, $quantity) {
                        try {
                            $this->app->make(StockService::class)->issue(InventoryItem::findOrFail($itemId), $storeId, $quantity, null, ['type' => 'general', 'label' => 'race'], 'direct_issue', null);

                            return 0;
                        } catch (ApiException $e) {
                            return $e->errorCode === 'insufficient_stock' ? 1 : 2;
                        }
                    }, $membership);
                } catch (\Throwable $e) {
                    fwrite(STDERR, $e->getMessage()."\n");
                }
                // End the child by signal: exit() would run PHPUnit's and Laravel's shutdown handlers.
                posix_kill(getmypid(), $code === 0 ? SIGUSR1 : ($code === 1 ? SIGUSR2 : SIGTERM));
            }
            $pids[] = $pid;
        }

        $results = ['ok' => 0, 'insufficient' => 0, 'error' => 0];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : (pcntl_wifsignaled($status) ? match (pcntl_wtermsig($status)) {
                SIGUSR1 => 0, SIGUSR2 => 1, default => 2
            } : 2);
            $results[match ($code) {
                0 => 'ok', 1 => 'insufficient', default => 'error'
            }]++;
        }
        DB::purge();
        DB::reconnect();

        return $results;
    }
}
