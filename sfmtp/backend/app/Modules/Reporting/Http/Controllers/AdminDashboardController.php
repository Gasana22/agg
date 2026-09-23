<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Modules\Billing\Domain\Models\Subscription;
use App\Modules\Billing\Domain\Models\SubscriptionPayment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Platform\Application\PlatformPermissions;
use App\Modules\Platform\Application\SystemHealth;
use App\Modules\Support\Domain\Models\SupportTicket;
use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\Domain\Models\Farm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * System Administrator dashboard (docs/05 §3.1). Platform tables and farm
 * metadata only — never farm operational records. Each KPI and widget is
 * shown only to platform roles with the matching capability.
 */
class AdminDashboardController
{
    public function __construct(
        private readonly PlatformPermissions $permissions,
        private readonly SystemHealth $health,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $can = fn (string $cap) => $this->permissions->allows($user, $cap);
        $currency = 'UGX';

        $kpis = [];
        $widgets = [];

        if ($can('farms.view')) {
            $byStatus = Farm::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
            $count = fn (FarmStatus $s) => (int) ($byStatus[$s->value] ?? 0);
            array_push($kpis,
                $this->kpi('farms.registered', 'Registered farms', (int) $byStatus->sum()),
                $this->kpi('farms.pending', 'Pending approval', $count(FarmStatus::Pending)),
                $this->kpi('farms.active', 'Active farms', $count(FarmStatus::Active)),
                $this->kpi('farms.suspended', 'Suspended farms', $count(FarmStatus::Suspended)),
                $this->kpi('users.total', 'Users', User::count()),
            );
            $widgets[] = $this->list('farm_approvals', $count(FarmStatus::Pending),
                Farm::where('status', FarmStatus::Pending->value)->orderBy('created_at')->limit(10)->get()->map(fn (Farm $f) => [
                    'id' => $f->id,
                    'title' => $f->name,
                    'subtitle' => trim($f->code.' · '.($f->district ?? ''), ' ·'),
                    'at' => $f->created_at->toIso8601ZuluString(),
                    'href' => "/admin/farms/{$f->id}",
                ])->all());
        }

        if ($can('subscriptions.view')) {
            $live = Subscription::with('plan')->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Grace->value])->get();
            $mrr = $live->filter(fn ($s) => $s->plan->currency === $currency)->sum(fn ($s) => $s->plan->monthlyPrice());
            $revenue = SubscriptionPayment::where('status', 'succeeded')->where('currency', $currency)->where('paid_at', '>=', now()->startOfMonth())->sum('amount');
            $failed = SubscriptionPayment::where('status', 'failed')->where('created_at', '>=', now()->subDays(30))->count();
            $expiring = Subscription::with(['plan', 'organization'])
                ->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value])
                ->whereDate('current_period_end', '<=', now()->addDays(14))
                ->orderBy('current_period_end')->limit(10)->get();

            array_push($kpis,
                $this->kpi('subs.active', 'Paying subscriptions', $live->count()),
                $this->kpi('subs.trialing', 'On trial', Subscription::where('status', SubscriptionStatus::Trialing->value)->count()),
                $this->kpi('subs.grace', 'In grace period', Subscription::where('status', SubscriptionStatus::Grace->value)->count()),
                ['key' => 'platform.mrr', 'label' => 'Monthly recurring revenue', 'value' => ['amount' => number_format($mrr, 2, '.', ''), 'currency' => $currency], 'format' => 'money', 'delta' => null],
                ['key' => 'platform.revenue', 'label' => 'Revenue this month', 'value' => ['amount' => number_format((float) $revenue, 2, '.', ''), 'currency' => $currency], 'format' => 'money', 'delta' => null],
                $this->kpi('payments.failed_30d', 'Failed payments (30 days)', $failed),
            );
            $widgets[] = $this->list('expiring_subscriptions', $expiring->count(), $expiring->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->organization->name,
                'subtitle' => "{$s->plan->name} · {$s->status->value} · ends {$s->current_period_end->toDateString()}",
                'href' => "/admin/subscriptions/{$s->id}",
            ])->all());
        }

        if ($can('support.view')) {
            $open = SupportTicket::whereIn('status', ['open', 'pending'])->count();
            $kpis[] = $this->kpi('tickets.open', 'Open support tickets', $open);
            $widgets[] = $this->list('support_queue', $open, SupportTicket::with('organization')->where('status', 'open')
                ->orderByDesc('last_activity_at')->limit(10)->get()->map(fn ($t) => [
                    'id' => $t->id,
                    'title' => $t->subject,
                    'subtitle' => "{$t->reference} · {$t->organization->name} · {$t->priority}",
                    'at' => $t->last_activity_at->toIso8601ZuluString(),
                    'href' => "/admin/support/{$t->id}",
                ])->all());
        }

        if ($can('system.view')) {
            foreach ($this->health->checks() as $name => $check) {
                if (in_array($name, ['database', 'cache'], true)) {
                    $kpis[] = ['key' => "health.{$name}", 'label' => ucfirst($name), 'value' => $check['status'], 'format' => 'status', 'meta' => ['latency_ms' => $check['latency_ms']], 'delta' => null];
                }
            }
            $last = DB::table('backup_runs')->orderByDesc('finished_at')->first();
            $kpis[] = [
                'key' => 'backup.last',
                'label' => 'Last backup',
                // "down" when the last run failed or none succeeded in 26 hours (daily schedule + slack).
                'value' => $last && $last->status === 'success' && now()->diffInHours($last->finished_at, true) <= 26 ? 'ok' : 'down',
                'format' => 'status',
                'meta' => ['finished_at' => $last ? Carbon::parse($last->finished_at)->toIso8601ZuluString() : null],
                'delta' => null,
            ];
            $kpis[] = $this->kpi('jobs.failed', 'Failed jobs', DB::table('failed_jobs')->count());
        }

        $actions = array_values(array_filter([
            $can('farms.approve') ? ['key' => 'approve_farm', 'label' => 'Review pending farms', 'target' => '/admin/farms?status=pending'] : null,
            $can('plans.manage') ? ['key' => 'manage_plans', 'label' => 'Plans & pricing', 'target' => '/admin/plans'] : null,
            $can('catalog.manage') ? ['key' => 'catalogues', 'label' => 'Global catalogues', 'target' => '/admin/catalog'] : null,
            $can('support.view') ? ['key' => 'support', 'label' => 'Support queue', 'target' => '/admin/support'] : null,
            $can('system.view') ? ['key' => 'system', 'label' => 'System health', 'target' => '/admin/system'] : null,
        ]));

        return new JsonResponse(['data' => [
            'dashboard' => 'admin',
            'generated_at' => now()->toIso8601ZuluString(),
            'kpis' => $kpis,
            'widgets' => $widgets,
            'quick_actions' => $actions,
            'alerts' => [],
        ]]);
    }

    private function kpi(string $key, string $label, int $value): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'format' => 'number', 'delta' => null];
    }

    private function list(string $key, int $total, array $items): array
    {
        return ['key' => $key, 'type' => 'action_list', 'inline' => true, 'data' => ['total' => $total, 'items' => $items]];
    }
}
