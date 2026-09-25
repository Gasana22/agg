<?php

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Application\DashboardRegistry;
use App\Modules\Reporting\Http\Controllers\DashboardController;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Dashboard performance bench (Phase 13 exit gate: p95 under 800 ms from
 * cache and under 3 s cold). Each request runs the dashboard controller as
 * the farm owner, inside the farm's context, and encodes the JSON body; the
 * authentication middleware in front of it is left out. A cold request asks
 * for a custom period no one asked for before, so nothing is cached; a warm
 * one repeats the same query.
 */
class BenchDashboards extends Command
{
    protected $signature = 'reporting:bench {farm? : Farm id or code (default: the first active farm)}
        {--runs=20 : Requests per dashboard and mode}
        {--dashboards= : Comma-separated dashboards (default: all)}
        {--json : Print the results as JSON}';

    protected $description = 'Measure dashboard response times, cold and from cache, and check them against the budget.';

    public const BUDGET_WARM_MS = 800;

    public const BUDGET_COLD_MS = 3000;

    private Farm $farm;

    private FarmUser $membership;

    public function handle(TenantContext $context): int
    {
        $farm = $this->argument('farm')
            ? Farm::where('id', $this->argument('farm'))->orWhere('code', $this->argument('farm'))->first()
            : Farm::where('status', 'active')->orderBy('created_at')->first();
        if ($farm === null) {
            $this->error('No farm found.');

            return self::FAILURE;
        }
        $this->farm = $farm;
        $this->membership = FarmUser::with('user')->where('farm_id', $farm->id)->where('is_owner', true)->firstOrFail();
        Auth::setUser($this->membership->user);
        $runs = max(3, (int) $this->option('runs'));
        $dashboards = $this->option('dashboards') ? explode(',', $this->option('dashboards')) : array_diff(DashboardRegistry::FARM_DASHBOARDS, ['worker']);

        $results = [];
        $ok = true;
        foreach ($dashboards as $dashboard) {
            $base = $dashboard;
            $cold = [];
            $warm = [];
            $day = CarbonImmutable::now($farm->timezone)->subYears(2)->subDays(random_int(0, 3000));
            for ($i = 0; $i < $runs; $i++) {
                $from = $day->subDays($i + 1)->toDateString();
                $cold[] = $this->time($context, $base, ['period' => 'custom', 'from' => $from, 'to' => $day->toDateString()]);
            }
            $this->time($context, $base, ['period' => '30d']);
            for ($i = 0; $i < $runs; $i++) {
                $warm[] = $this->time($context, $base, ['period' => '30d']);
            }
            $row = ['dashboard' => $dashboard, 'cold_p50' => $this->pct($cold, 50), 'cold_p95' => $this->pct($cold, 95),
                'warm_p50' => $this->pct($warm, 50), 'warm_p95' => $this->pct($warm, 95)];
            $row['pass'] = $row['cold_p95'] < self::BUDGET_COLD_MS && $row['warm_p95'] < self::BUDGET_WARM_MS;
            $ok = $ok && $row['pass'];
            $results[] = $row;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['farm' => $farm->id, 'runs' => $runs, 'results' => $results], JSON_PRETTY_PRINT));
        } else {
            $this->table(['Dashboard', 'Cold p50 ms', 'Cold p95 ms', 'Cached p50 ms', 'Cached p95 ms', 'Within budget'],
                array_map(fn ($r) => [$r['dashboard'], $r['cold_p50'], $r['cold_p95'], $r['warm_p50'], $r['warm_p95'], $r['pass'] ? 'yes' : 'NO'], $results));
            $this->line(sprintf('Budget: cold p95 < %d ms, cached p95 < %d ms.', self::BUDGET_COLD_MS, self::BUDGET_WARM_MS));
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function time(TenantContext $context, string $dashboard, array $query): float
    {
        $start = hrtime(true);
        $response = $context->run($this->farm, fn () => app(DashboardController::class)->show(Request::create('/', 'GET', $query), $this->farm->id, $dashboard), $this->membership);
        $response->getContent();

        return (hrtime(true) - $start) / 1e6;
    }

    private function pct(array $values, int $p): float
    {
        sort($values);
        $idx = (int) ceil($p / 100 * count($values)) - 1;

        return round($values[max(0, $idx)], 1);
    }
}
