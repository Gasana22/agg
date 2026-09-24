<?php

namespace App\Modules\Reporting\Application;

use App\Modules\FarmStructure\Domain\Models\Plot;
use App\Modules\Tenancy\Domain\Enums\MembershipStatus;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Domain\Enums\BatchStatus;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Domain\Models\TraceEvent;
use Illuminate\Support\Facades\DB;

/**
 * Metric catalogue (docs/05 §4). Phases 1–3 cover membership, farm
 * structure and traceability; each later phase adds its module's metrics
 * here, then moves heavy ones to precomputed farm_daily_metrics (Phase 13).
 */
class FarmMetrics
{
    public function __construct(private readonly TenantContext $context) {}

    public function membersActive(): int
    {
        return DB::table('farm_users')
            ->where('farm_id', $this->context->farmId())
            ->where('status', MembershipStatus::Active->value)
            ->count();
    }

    public function plots(): int
    {
        return Plot::count();
    }

    /** Hectares inside drawn plot boundaries. */
    public function mappedAreaHa(): float
    {
        return round((float) Plot::sum('area_ha'), 2);
    }

    public function openBatches(): int
    {
        return TraceBatch::where('status', BatchStatus::Open->value)->count();
    }

    /** QR scans in the period, by the farm's local day. */
    public function qrScans(Period $period, string $timezone): int
    {
        return (int) DB::table('trace_qr_scans')->where('farm_id', $this->context->farmId())
            ->whereBetween('day', [$period->from->setTimezone($timezone)->toDateString(), $period->to->setTimezone($timezone)->toDateString()])
            ->sum('scans');
    }

    public function traceEvents(Period $period): int
    {
        return TraceEvent::whereBetween('recorded_at', [$period->from, $period->to])->count();
    }

    /** @return array<int,array{date:string,count:int}> events per local day */
    public function traceEventsPerDay(Period $period, string $timezone): array
    {
        $counts = [];
        TraceEvent::whereBetween('recorded_at', [$period->from, $period->to])
            ->select('recorded_at')
            ->orderBy('recorded_at')
            ->lazy(1000)
            ->each(function (TraceEvent $e) use (&$counts, $timezone) {
                $day = $e->recorded_at->setTimezone($timezone)->toDateString();
                $counts[$day] = ($counts[$day] ?? 0) + 1;
            });

        $series = [];
        for ($d = $period->from->setTimezone($timezone)->startOfDay(); $d <= $period->to; $d = $d->addDay()) {
            $series[] = ['date' => $d->toDateString(), 'count' => $counts[$d->toDateString()] ?? 0];
        }

        return $series;
    }

    /** @return array<int,array> */
    public function recentTraceEvents(int $limit = 10): array
    {
        return TraceEvent::with('batch:id,batch_code,kind,name')
            ->orderByDesc('recorded_at')
            ->orderByDesc('farm_seq')
            ->limit($limit)
            ->get()
            ->map(fn (TraceEvent $e) => [
                'id' => $e->id,
                'title' => str_replace('_', ' ', ucfirst($e->event_type)),
                'subtitle' => $e->batch->batch_code.($e->batch->name ? ' · '.$e->batch->name : ''),
                'at' => $e->occurred_at->toIso8601ZuluString(),
                'href' => "/farms/{$e->farm_id}/traceability/batches/{$e->batch_id}",
            ])
            ->all();
    }
}
