<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Application\ActivityHeatmap;
use App\Modules\Reporting\Application\DashboardRegistry;
use App\Modules\Reporting\Application\Period;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class HeatmapController
{
    public function __invoke(Request $request, ActivityHeatmap $heatmap, DashboardRegistry $registry, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'period' => ['sometimes', Rule::in(Period::KEYS)],
            'from' => ['required_if:period,custom', 'date'],
            'to' => ['required_if:period,custom', 'date', 'after_or_equal:from'],
            'layers' => ['sometimes', 'string', 'max:200'],
            'cell' => ['sometimes', 'integer', 'min:10', 'max:1000'],
        ]);
        $period = Period::resolve($data['period'] ?? '30d', $context->farm()->timezone, $data['from'] ?? null, $data['to'] ?? null);
        $layers = isset($data['layers']) ? array_values(array_filter(array_map('trim', explode(',', $data['layers'])))) : null;
        $cell = (int) ($data['cell'] ?? 50);

        $key = sprintf('farm:%s:heatmap:%s:%s:%s:%s:%d:%s', $context->farmId(), $period->key, $period->from->timestamp, $period->to->timestamp,
            implode(',', $layers ?? ['*']), $cell, $registry->permissionFingerprint());

        return new JsonResponse(['data' => Cache::remember($key, config('sfmtp.dashboards.cache_ttl'), fn () => $heatmap->build($period, $layers, $cell))]);
    }
}
