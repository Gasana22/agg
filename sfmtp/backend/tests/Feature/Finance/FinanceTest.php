<?php

namespace Tests\Feature\Finance;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\FarmSettings;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Workforce\Application\Activities;
use App\Modules\Workforce\Domain\Models\Attendance;
use App\Modules\Workforce\Domain\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    private Farm $farm;

    private User $owner;

    private User $manager;

    private User $accountant;

    private User $agronomist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->farm = $this->farm();
        $this->owner = $this->ownerOf($this->farm);
        $this->manager = $this->memberWithRole($this->farm, 'manager');
        $this->accountant = $this->memberWithRole($this->farm, 'accountant');
        $this->agronomist = $this->memberWithRole($this->farm, 'agronomist');
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}{$path}";
    }

    private function as(User $user)
    {
        return $this->asUser($user);
    }

    /** @return array<string, array{id:string, balance:float}> by code, checking the trial balance on the way */
    private function accounts(): array
    {
        $tb = $this->as($this->accountant)->getJson($this->url('/ledger/accounts'))->assertOk()->json();
        $this->assertEquals($tb['meta']['total_debit'], $tb['meta']['total_credit'], 'The trial balance must balance.');

        return collect($tb['data'])->keyBy('code')->all();
    }

    private function balance(string $code): float
    {
        return (float) $this->accounts()[$code]['balance'];
    }

    private function cycle(): array
    {
        $plot = $this->as($this->owner)->postJson($this->url('/structure/plots'), ['name' => 'Plot A', 'declared_area_ha' => 2])->assertCreated()->json('data.id');
        $crop = $this->as($this->agronomist)->postJson($this->url('/crops'), ['global_variety_id' => DB::table('global_crop_varieties')->where('code', 'longe_5')->value('id')])->json('data.id');

        return $this->as($this->agronomist)->postJson($this->url('/crop-cycles'), ['plot_id' => $plot, 'crop_id' => $crop, 'planted_on' => now()->subDays(30)->toDateString()])->assertCreated()->json('data');
    }

    public function test_accounts_and_manual_entries_keep_control_accounts_to_their_documents(): void
    {
        $a = $this->accounts();
        $this->assertTrue($a['1000']['is_cash']);
        $this->assertTrue($a['1300']['is_control']);

        $momo = $this->as($this->accountant)->postJson($this->url('/ledger/accounts'), ['code' => '1020', 'name' => 'MTN Mobile Money', 'type' => 'asset', 'is_cash' => true])->assertCreated()->json('data');
        $this->as($this->accountant)->postJson($this->url('/ledger/accounts'), ['code' => '1020', 'name' => 'Again', 'type' => 'asset'])->assertStatus(422);
        $this->as($this->accountant)->postJson($this->url('/ledger/accounts'), ['code' => '5010', 'name' => 'Wrong type', 'type' => 'income'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->as($this->accountant)->patchJson($this->url("/ledger/accounts/{$a['1300']['id']}"), ['is_active' => false])->assertStatus(422);
        $this->as($this->manager)->postJson($this->url('/ledger/accounts'), ['code' => '5010', 'name' => 'x', 'type' => 'expense'])->assertForbidden();

        // Capital paid in: a manual entry. Posting to a control account is refused.
        $entry = $this->as($this->accountant)->postJson($this->url('/ledger/entries'), ['posted_on' => now()->toDateString(), 'memo' => 'Owner capital', 'lines' => [
            ['account_id' => $momo['id'], 'debit' => 2000000], ['account_id' => $a['3000']['id'], 'credit' => 2000000],
        ]])->assertCreated()->assertJsonPath('data.source.type', 'manual')->json('data');
        $this->as($this->accountant)->postJson($this->url('/ledger/entries'), ['posted_on' => now()->toDateString(), 'memo' => 'Fix stock', 'lines' => [
            ['account_id' => $a['1300']['id'], 'debit' => 10], ['account_id' => $a['3000']['id'], 'credit' => 10],
        ]])->assertStatus(422)->assertJsonValidationErrors('lines.0.account_id');
        $this->as($this->accountant)->postJson($this->url('/ledger/entries'), ['posted_on' => now()->toDateString(), 'memo' => 'Unbalanced', 'lines' => [
            ['account_id' => $momo['id'], 'debit' => 10], ['account_id' => $a['3000']['id'], 'credit' => 9],
        ]])->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->assertEquals(2000000, $this->balance('1020'));
        $this->as($this->accountant)->postJson($this->url("/ledger/entries/{$entry['id']}/reverse"), ['reason' => 'Posted twice'])->assertCreated();
        $this->assertEquals(0, $this->balance('1020'));
    }

    public function test_expenses_need_approval_by_someone_else_and_the_owner_above_the_threshold(): void
    {
        $a = $this->accounts();
        $cycle = $this->cycle();
        $this->inFarm($this->farm, fn () => app(FarmSettings::class)->update($this->farm, ['approval_thresholds' => ['expense' => 500000]]));

        // The manager asks for fuel for the maize; the accountant approves it (within the threshold).
        $fuel = $this->as($this->manager)->postJson($this->url('/expenses'), [
            'account_id' => $a['5400']['id'], 'amount' => 150000, 'spent_on' => now()->toDateString(), 'payee' => 'Shell Kakiri',
            'description' => 'Diesel for the tractor', 'cost_center_type' => 'crop_cycle', 'cost_center_id' => $cycle['id'],
        ])->assertCreated()->assertJsonPath('data.status', 'requested')->assertJsonPath('data.cost_center.type', 'crop_cycle')->json('data');
        $this->as($this->manager)->postJson($this->url("/expenses/{$fuel['id']}/approve"))->assertForbidden();
        $this->as($this->accountant)->postJson($this->url("/expenses/{$fuel['id']}/approve"))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertEquals([150000, 150000], [$this->balance('5400'), $this->balance('2000')]);
        $this->as($this->manager)->getJson($this->url('/expenses'))->assertOk()->assertJsonCount(1, 'data');

        // Above the threshold the accountant cannot approve; the owner can.
        $big = $this->as($this->manager)->postJson($this->url('/expenses'), ['account_id' => $a['5600']['id'], 'amount' => 900000, 'spent_on' => now()->toDateString(), 'description' => 'Borehole pump repair'])->json('data');
        $this->as($this->accountant)->postJson($this->url("/expenses/{$big['id']}/approve"))->assertForbidden()->assertJsonPath('code', 'approval_required');
        $this->as($this->owner)->postJson($this->url("/expenses/{$big['id']}/approve"))->assertOk();

        // The accountant's own receipt within the threshold, already paid in cash, is posted at once.
        $cash = $this->as($this->accountant)->postJson($this->url('/expenses'), ['account_id' => $a['5500']['id'], 'amount' => 80000, 'spent_on' => now()->toDateString(),
            'description' => 'Vet visit', 'paid_from_account_id' => $a['1000']['id']])->assertCreated()->assertJsonPath('data.status', 'paid')->json('data');
        $this->assertEquals(-80000, $this->balance('1000'));
        // …but above the threshold even the accountant's own entry waits for the owner.
        $own = $this->as($this->accountant)->postJson($this->url('/expenses'), ['account_id' => $a['5900']['id'], 'amount' => 600000, 'spent_on' => now()->toDateString(), 'description' => 'Fencing wire'])
            ->assertJsonPath('data.status', 'requested')->json('data');
        $this->as($this->accountant)->postJson($this->url("/expenses/{$own['id']}/approve"))->assertForbidden();
        $this->as($this->owner)->postJson($this->url("/expenses/{$own['id']}/reject"), ['note' => 'Use the old wire'])->assertOk()->assertJsonPath('data.status', 'rejected');

        // Paying the fuel clears payables; the payment is capped at what is owed.
        $this->as($this->accountant)->postJson($this->url('/payments'), ['payable_type' => 'expense', 'payable_id' => $fuel['id'], 'amount' => 150001, 'method' => 'cash', 'account_id' => $a['1000']['id']])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $pay = $this->as($this->accountant)->postJson($this->url('/payments'), ['payable_type' => 'expense', 'payable_id' => $fuel['id'], 'amount' => 150000, 'method' => 'mobile_money', 'account_id' => $a['1010']['id'], 'reference' => 'MP260924.1'])
            ->assertCreated()->assertJsonPath('data.direction', 'out')->assertJsonPath('data.payable.code', $fuel['code'])->json('data');
        $this->as($this->accountant)->getJson($this->url("/expenses/{$fuel['id']}"))->assertJsonPath('data.status', 'paid');
        $this->as($this->accountant)->postJson($this->url('/payments'), ['payable_type' => 'expense', 'payable_id' => $fuel['id'], 'amount' => 1, 'method' => 'cash', 'account_id' => $a['1000']['id']])->assertStatus(409);
        $this->as($this->accountant)->postJson($this->url("/expenses/{$fuel['id']}/void"), ['reason' => 'Wrong amount'])->assertStatus(409)->assertJsonPath('code', 'has_payments');

        // Voiding reverses: payment first, then the expense; the books end where they started for it.
        $this->as($this->accountant)->postJson($this->url("/payments/{$pay['id']}/void"), ['reason' => 'Paid from the wrong phone'])->assertOk()->assertJsonPath('data.status', 'void');
        $this->as($this->accountant)->getJson($this->url("/expenses/{$fuel['id']}"))->assertJsonPath('data.status', 'approved')->assertJsonPath('data.paid_amount', 0);
        $this->as($this->accountant)->postJson($this->url("/expenses/{$fuel['id']}/void"), ['reason' => 'Duplicate of a fuel receipt'])->assertOk()->assertJsonPath('data.status', 'void');
        $this->as($this->accountant)->postJson($this->url("/expenses/{$cash['id']}/void"), ['reason' => 'Test'])->assertOk();
        $b = $this->accounts();
        $this->assertEquals([0, 900000, 0, 0], [(float) $b['5400']['balance'], (float) $b['2000']['balance'], (float) $b['1000']['balance'], (float) $b['1010']['balance']]);
        $this->assertSame(2, $this->inFarm($this->farm, fn () => DB::table('ledger_entries')->where('source_type', 'expense')->whereNotNull('reverses_entry_id')->count()));
    }

    public function test_income_is_posted_and_voided_by_reversal(): void
    {
        $a = $this->accounts();
        $inc = $this->as($this->accountant)->postJson($this->url('/income'), ['account_id' => $a['4100']['id'], 'received_into_account_id' => $a['1000']['id'], 'amount' => 45000,
            'received_on' => now()->toDateString(), 'payer' => 'Neighbour', 'description' => 'Manure, 3 tractor loads'])->assertCreated()->assertJsonPath('data.code', 'INC-001')->json('data');
        $this->as($this->accountant)->postJson($this->url('/income'), ['account_id' => $a['5400']['id'], 'received_into_account_id' => $a['1000']['id'], 'amount' => 1,
            'received_on' => now()->toDateString(), 'description' => 'Wrong account'])->assertStatus(422)->assertJsonValidationErrors('account_id');
        $this->as($this->accountant)->postJson($this->url('/income'), ['account_id' => $a['4100']['id'], 'received_into_account_id' => $a['1200']['id'], 'amount' => 1,
            'received_on' => now()->toDateString(), 'description' => 'Not a money account'])->assertStatus(422)->assertJsonValidationErrors('received_into_account_id');
        $this->assertEquals([45000, 45000], [$this->balance('1000'), $this->balance('4100')]);
        $this->as($this->manager)->postJson($this->url('/income'), [])->assertForbidden();
        $this->as($this->accountant)->postJson($this->url("/income/{$inc['id']}/void"), ['reason' => 'Neighbour returned the manure'])->assertOk()->assertJsonPath('data.status', 'void');
        $this->as($this->accountant)->postJson($this->url("/income/{$inc['id']}/void"), ['reason' => 'Again'])->assertStatus(409);
        $this->assertEquals(0, $this->balance('4100'));
    }

    public function test_payroll_from_attendance_charged_to_the_work_done(): void
    {
        $cycle = $this->cycle();
        $a = $this->accounts();
        $mk = fn (string $name, ?float $rate) => $this->as($this->owner)->postJson($this->url('/workers'), ['full_name' => $name, 'employment_type' => 'casual', 'daily_rate' => $rate])->assertCreated()->json('data');
        [$okello, $nakato, $norate] = [$mk('Okello Joseph', 10000), $mk('Nakato Sarah', 12000), $mk('No Rate', null)];
        $tz = $this->farm->timezone;
        $start = CarbonImmutable::now($tz)->subDays(7)->startOfDay();
        $this->actingAs($this->owner, 'api');
        app(TenantContext::class)->run($this->farm, function () use ($okello, $nakato, $norate, $start, $cycle) {
            foreach ([[$okello, 3], [$nakato, 2], [$norate, 2]] as [$w, $days]) {
                foreach (range(0, $days - 1) as $d) {
                    $day = $start->addDays($d);
                    Attendance::create(['worker_id' => $w['id'], 'work_date' => $day->toDateString(), 'check_in_at' => $day->setTime(7, 0)->utc(), 'check_out_at' => $day->setTime(15, 0)->utc(), 'source' => 'manual']);
                }
            }
            // Okello weeded the maize for 3 hours and dug a drain (general work) for 1 hour.
            $weed = DB::table('global_activity_types')->where('code', 'weeding')->value('id');
            $labour = DB::table('global_activity_types')->where('code', 'general_labour')->value('id');
            $plan = app(Activities::class);
            foreach ([[$weed, 'crop_cycle', $cycle['id'], 180], [$labour, 'general', null, 60]] as [$type, $st, $sid, $min]) {
                $act = $plan->create(['activity_type_id' => $type, 'subject_type' => $st, 'subject_id' => $sid, 'planned_on' => $start->toDateString(), 'worker_ids' => [$okello['id']]]);
                Task::where('activity_id', $act->id)->update(['status' => 'verified', 'worked_minutes' => $min, 'verified_at' => $start->addDay()->setTime(16, 0)->utc()]);
            }
        }, FarmUser::where('farm_id', $this->farm->id)->where('user_id', $this->owner->id)->first());

        $period = ['period_start' => $start->toDateString(), 'period_end' => $start->addDays(6)->toDateString()];
        $this->as($this->manager)->postJson($this->url('/payroll-runs'), $period)->assertForbidden();
        $run = $this->as($this->accountant)->postJson($this->url('/payroll-runs'), $period)->assertCreated()->assertJsonPath('data.code', 'PRL-001')->json('data');
        $this->assertEquals([54000, 2], [$run['total_gross'], count($run['lines'])]);    // 3 × 10,000 + 2 × 12,000; no rate, no pay
        $ok = collect($run['lines'])->firstWhere('worker.id', $okello['id']);
        $this->assertEquals([3, 1440, 2, 30000], [$ok['days_worked'], $ok['minutes_worked'], $ok['tasks_verified'], $ok['gross']]);
        $this->assertEquals([22500, 7500], [collect($ok['allocation'])->firstWhere('type', 'crop_cycle')['amount'], collect($ok['allocation'])->firstWhere('type', null)['amount']]);
        $this->as($this->accountant)->postJson($this->url('/payroll-runs'), $period)->assertStatus(409)->assertJsonPath('code', 'overlapping_payroll');

        // The manager sees hours, never wages.
        $hours = $this->as($this->manager)->getJson($this->url("/payroll-runs/{$run['id']}"))->assertOk()->assertJsonMissingPath('data.total_net')->json('data');
        $this->assertArrayNotHasKey('gross', $hours['lines'][0]);

        // A bonus and an advance taken back; four eyes on approval.
        $this->as($this->accountant)->patchJson($this->url("/payroll-runs/{$run['id']}/lines/{$ok['id']}"), ['bonus' => 5000, 'deductions' => 8000, 'note' => 'Advance of 8,000'])
            ->assertOk()->assertJsonPath('data.total_gross', 59000)->assertJsonPath('data.total_net', 51000);
        $this->as($this->accountant)->postJson($this->url("/payroll-runs/{$run['id']}/approve"))->assertForbidden();   // no approve permission
        $this->as($this->owner)->postJson($this->url("/payroll-runs/{$run['id']}/approve"))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->as($this->accountant)->patchJson($this->url("/payroll-runs/{$run['id']}/lines/{$ok['id']}"), ['bonus' => 1])->assertStatus(409);

        $b = $this->accounts();
        $this->assertEquals([59000, 51000, 8000], [(float) $b['5300']['balance'], (float) $b['2200']['balance'], (float) $b['2210']['balance']]);
        // Okello's 35,000 gross is split 3:1 over the maize and general work.
        $maizeWages = $this->inFarm($this->farm, fn () => DB::table('ledger_lines')->where('cost_center_id', $cycle['id'])->sum('debit'));
        $this->assertEquals(26250, $maizeWages);

        $this->as($this->accountant)->postJson($this->url('/payments'), ['payable_type' => 'payroll_run', 'payable_id' => $run['id'], 'amount' => 51000, 'method' => 'cash', 'account_id' => $a['1000']['id']])->assertCreated();
        $this->as($this->accountant)->getJson($this->url("/payroll-runs/{$run['id']}"))->assertJsonPath('data.status', 'paid');
        $this->as($this->accountant)->postJson($this->url("/payroll-runs/{$run['id']}/cancel"), ['reason' => 'x x x'])->assertStatus(409);
        $this->assertEquals(0, $this->balance('2200'));
    }

    public function test_budgets_compare_with_the_ledger(): void
    {
        $a = $this->accounts();
        $cycle = $this->cycle();
        $budget = $this->as($this->accountant)->postJson($this->url('/budgets'), [
            'name' => 'Maize season B', 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
            'scope_type' => 'crop_cycle', 'scope_id' => $cycle['id'],
            'lines' => [['account_id' => $a['5400']['id'], 'amount' => 200000], ['account_id' => $a['4200']['id'], 'amount' => 3000000]],
        ])->assertCreated()->assertJsonPath('data.code', 'BUD-001')->json('data');
        $this->as($this->accountant)->postJson($this->url('/expenses'), ['account_id' => $a['5400']['id'], 'amount' => 150000, 'spent_on' => now()->toDateString(), 'description' => 'Diesel',
            'cost_center_type' => 'crop_cycle', 'cost_center_id' => $cycle['id']])->assertJsonPath('data.status', 'approved');
        $this->as($this->accountant)->postJson($this->url('/expenses'), ['account_id' => $a['5400']['id'], 'amount' => 99000, 'spent_on' => now()->toDateString(), 'description' => 'Diesel for the car']);   // no cost centre: not this budget

        $lines = collect($this->as($this->accountant)->getJson($this->url("/budgets/{$budget['id']}"))->assertOk()->json('data.lines'))->keyBy('code');
        $this->assertEquals([200000, 150000, 50000, 75.0], [$lines['5400']['budget'], $lines['5400']['actual'], $lines['5400']['variance'], $lines['5400']['used_pct']]);
        $this->as($this->agronomist)->getJson($this->url('/budgets'))->assertForbidden();
        $this->as($this->accountant)->postJson($this->url('/budgets'), ['name' => 'Bad', 'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
            'lines' => [['account_id' => $a['1000']['id'], 'amount' => 1]]])->assertStatus(422)->assertJsonValidationErrors('lines.0.account_id');
    }
}
