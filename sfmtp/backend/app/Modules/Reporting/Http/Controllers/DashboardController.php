<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Application\DashboardRegistry;
use App\Modules\Reporting\Application\Period;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController
{
    public function __construct(
        private readonly DashboardRegistry $registry,
        private readonly TenantContext $context,
    ) {}

    public function show(Request $request, string $farm, string $dashboard): JsonResponse
    {
        $period = $this->period($request);

        $data = Cache::remember(
            $this->cacheKey($dashboard, 'summary', $period),
            config('sfmtp.dashboards.cache_ttl'),
            fn () => $this->registry->summary($dashboard, $period),
        );

        return new JsonResponse(['data' => $data]);
    }

    public function widget(Request $request, string $farm, string $dashboard, string $widget): JsonResponse
    {
        $period = $this->period($request);

        $data = Cache::remember(
            $this->cacheKey($dashboard, "widget:{$widget}", $period),
            config('sfmtp.dashboards.cache_ttl'),
            fn () => $this->registry->widget($dashboard, $widget, $period),
        );

        return new JsonResponse(['data' => $data]);
    }

    private function period(Request $request): Period
    {
        $data = $request->validate([
            'period' => ['sometimes', 'in:'.implode(',', Period::KEYS)],
            'from' => ['required_if:period,custom', 'date'],
            'to' => ['required_if:period,custom', 'date', 'after_or_equal:from'],
        ]);

        return Period::resolve($data['period'] ?? '30d', $this->context->farm()->timezone, $data['from'] ?? null, $data['to'] ?? null);
    }

    private function cacheKey(string $dashboard, string $part, Period $period): string
    {
        return sprintf(
            'farm:%s:dash:%s:%s:%s:%s:%s:%s',
            $this->context->farmId(),
            $dashboard,
            $part,
            $period->key,
            $period->from->timestamp,
            $period->to->timestamp,
            $this->registry->permissionFingerprint(),
        );
    }
}
