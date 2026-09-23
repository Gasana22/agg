<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\Domain\Models\Farm;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * System Administrator dashboard (docs/05 §3.1). Platform tables and farm
 * *metadata* only — never farm operational records. Subscriptions, revenue,
 * backups and support tickets are added in Phase 2.
 */
class AdminDashboardController
{
    public function __invoke(): JsonResponse
    {
        $byStatus = Farm::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $count = fn (FarmStatus $s) => (int) ($byStatus[$s->value] ?? 0);

        $kpis = [
            $this->kpi('farms.registered', 'Registered farms', (int) $byStatus->sum()),
            $this->kpi('farms.pending', 'Pending approval', $count(FarmStatus::Pending)),
            $this->kpi('farms.active', 'Active farms', $count(FarmStatus::Active)),
            $this->kpi('farms.suspended', 'Suspended farms', $count(FarmStatus::Suspended)),
            $this->kpi('users.total', 'Users', User::count()),
            $this->health('health.db', 'Database', fn () => DB::select('select 1')),
            $this->health('health.cache', 'Cache', fn () => Cache::store()->put('health:ping', 1, 10)),
        ];

        $pending = Farm::where('status', FarmStatus::Pending->value)->orderBy('created_at')->limit(10)
            ->get(['id', 'code', 'name', 'district', 'created_at']);

        return new JsonResponse(['data' => [
            'dashboard' => 'admin',
            'generated_at' => now()->toIso8601ZuluString(),
            'kpis' => $kpis,
            'widgets' => [[
                'key' => 'farm_approvals',
                'type' => 'action_list',
                'inline' => true,
                'data' => [
                    'total' => $count(FarmStatus::Pending),
                    'items' => $pending->map(fn (Farm $f) => [
                        'id' => $f->id,
                        'title' => $f->name,
                        'subtitle' => trim($f->code.' · '.($f->district ?? ''), ' ·'),
                        'at' => $f->created_at->toIso8601ZuluString(),
                    ]),
                ],
            ]],
            'quick_actions' => [
                ['key' => 'approve_farm', 'label' => 'Review pending farms', 'target' => '/admin/farms?status=pending'],
            ],
            'alerts' => [],
        ]]);
    }

    private function kpi(string $key, string $label, int $value): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'format' => 'number', 'delta' => null];
    }

    private function health(string $key, string $label, callable $probe): array
    {
        $start = hrtime(true);
        try {
            $probe();
            $ok = true;
        } catch (Throwable) {
            $ok = false;
        }

        return [
            'key' => $key,
            'label' => $label,
            'value' => $ok ? 'ok' : 'down',
            'format' => 'status',
            'meta' => ['latency_ms' => round((hrtime(true) - $start) / 1e6, 1)],
            'delta' => null,
        ];
    }
}
