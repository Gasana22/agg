<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Application\DashboardRegistry;
use App\Modules\Reporting\Application\MetricCatalogue;
use App\Modules\Reporting\Application\Period;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** The metric catalogue and one metric with its series (ADR-0017). */
class MetricController
{
    public function __construct(
        private readonly MetricCatalogue $catalogue,
        private readonly DashboardRegistry $registry,
        private readonly TenantContext $context,
    ) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => $this->catalogue->list()]);
    }

    public function show(Request $request, string $farm, string $metric): JsonResponse
    {
        $data = $request->validate([
            'period' => ['sometimes', 'in:'.implode(',', Period::KEYS)],
            'from' => ['required_if:period,custom', 'date'],
            'to' => ['required_if:period,custom', 'date', 'after_or_equal:from'],
        ]);
        $period = Period::resolve($data['period'] ?? '30d', $this->context->farm()->timezone, $data['from'] ?? null, $data['to'] ?? null);

        $key = sprintf('farm:%s:metric:%s:%s:%s:%s:%s', $this->context->farmId(), $metric, $period->key,
            $period->from->timestamp, $period->to->timestamp, $this->registry->permissionFingerprint());

        return new JsonResponse(['data' => Cache::remember($key, config('sfmtp.dashboards.cache_ttl'),
            fn () => $this->catalogue->show($metric, $period))]);
    }
}
