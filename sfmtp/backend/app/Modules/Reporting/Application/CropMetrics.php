<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Crops\Application\Units;
use App\Modules\Crops\Domain\Enums\CycleStage;
use App\Modules\Crops\Domain\Enums\ObservationStatus;
use App\Modules\Crops\Domain\Enums\OperationStatus;
use App\Modules\Crops\Domain\Models\CropCycle;
use App\Modules\Crops\Domain\Models\CropHarvest;
use App\Modules\Crops\Domain\Models\CropObservation;
use App\Modules\Crops\Domain\Models\CropOperation;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Crop metrics for the agronomist, owner and manager dashboards (docs/05
 * §3.4). Yields are reported in kilograms; harvests in non-mass units are
 * left out of the totals.
 */
class CropMetrics
{
    private const INCIDENT_KINDS = ['pest', 'disease'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly Units $units,
    ) {}

    public function anyCycle(): bool
    {
        return CropCycle::exists();
    }

    public function activeCycles(): int
    {
        return CropCycle::where('stage', '!=', CycleStage::Closed->value)->count();
    }

    public function plantedAreaHa(): float
    {
        return round((float) CropCycle::whereIn('stage', CycleStage::inField())->sum('area_ha'), 2);
    }

    public function nearHarvest(int $days = 14): int
    {
        $today = $this->today();

        return CropCycle::whereIn('stage', CycleStage::inField())
            ->whereBetween('expected_harvest_on', [$today->toDateString(), $today->addDays($days)->toDateString()])
            ->count();
    }

    /** Expected yield of the open cycles, in kg. */
    public function expectedYieldKg(): float
    {
        return round(CropCycle::where('stage', '!=', CycleStage::Closed->value)->whereNotNull('expected_yield')
            ->get(['expected_yield', 'yield_unit'])
            ->sum(fn ($c) => $this->units->toKg((float) $c->expected_yield, $c->yield_unit) ?? 0), 1);
    }

    /** Harvested in the period, in kg. */
    public function harvestedKg(Period $period): float
    {
        return round($this->harvestsIn($period)->sum(fn ($h) => $this->units->toKg((float) $h->quantity, $h->unit) ?? 0), 1);
    }

    /** Kilograms per hectare over the cycles harvested in the period. */
    public function yieldPerHa(Period $period): ?float
    {
        $harvests = $this->harvestsIn($period);
        if ($harvests->isEmpty()) {
            return null;
        }
        $area = (float) CropCycle::whereIn('id', $harvests->pluck('cycle_id')->unique())->sum('area_ha');

        return $area > 0 ? round($harvests->sum(fn ($h) => $this->units->toKg((float) $h->quantity, $h->unit) ?? 0) / $area, 1) : null;
    }

    public function openIncidents(): int
    {
        return CropObservation::whereIn('kind', self::INCIDENT_KINDS)->where('status', '!=', ObservationStatus::Resolved->value)->count();
    }

    /** Open cycles still inside a treatment's withholding period. */
    public function cyclesUnderWithholding(): int
    {
        return CropCycle::where('stage', '!=', CycleStage::Closed->value)->where('safe_harvest_on', '>', $this->today()->toDateString())->count();
    }

    /** @return array<int,array> open pests and diseases, most severe first */
    public function incidentAlerts(int $limit = 8): array
    {
        return CropObservation::with('cycle.plot', 'cycle.crop')
            ->whereIn('kind', self::INCIDENT_KINDS)
            ->where('status', '!=', ObservationStatus::Resolved->value)
            ->get()
            ->sortBy([fn ($a, $b) => $b->severity->rank() <=> $a->severity->rank(), fn ($a, $b) => $b->observed_at <=> $a->observed_at])
            ->take($limit)
            ->map(fn (CropObservation $o) => [
                'id' => $o->id,
                'title' => $o->title,
                'subtitle' => "{$o->cycle->crop->label()} · Plot {$o->cycle->plot->code} · {$o->cycle->code}",
                'at' => $o->observed_at->toIso8601ZuluString(),
                'badge' => ['label' => ucfirst($o->severity->value), 'tone' => in_array($o->severity->value, ['high', 'critical'], true) ? 'danger' : 'warning'],
                'href' => "/farms/{$o->farm_id}/crops/cycles/{$o->cycle_id}",
            ])
            ->values()
            ->all();
    }

    /** @return array<int,array> work waiting for verification, oldest first */
    public function operationsToVerify(int $limit = 8): array
    {
        return CropOperation::with('cycle.plot', 'recorder')
            ->where('status', OperationStatus::Recorded->value)
            ->orderBy('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn (CropOperation $op) => [
                'id' => $op->id,
                'title' => ucfirst(str_replace('_', ' ', $op->type->value))." · {$op->cycle->code}",
                'subtitle' => 'Plot '.$op->cycle->plot->code.($op->recorder ? " · by {$op->recorder->name}" : ''),
                'at' => $op->occurred_at->toIso8601ZuluString(),
                'href' => "/farms/{$op->farm_id}/crops/cycles/{$op->cycle_id}",
            ])
            ->all();
    }

    /** @return array<int,array> cycles by expected harvest date, next 30 days */
    public function upcomingHarvests(int $days = 30, int $limit = 8): array
    {
        $today = $this->today();

        return CropCycle::with('plot', 'crop')
            ->whereIn('stage', CycleStage::inField())
            ->whereBetween('expected_harvest_on', [$today->subDays(7)->toDateString(), $today->addDays($days)->toDateString()])
            ->orderBy('expected_harvest_on')
            ->limit($limit)
            ->get()
            ->map(fn (CropCycle $c) => [
                'id' => $c->id,
                'title' => "{$c->crop->label()} · Plot {$c->plot->code}",
                'subtitle' => "{$c->code} · ".round((float) $c->area_ha, 2).' ha'.($c->safe_harvest_on && $c->safe_harvest_on->isFuture() ? " · withholding until {$c->safe_harvest_on->toDateString()}" : ''),
                'at' => $c->expected_harvest_on->setTimezone($this->context->farm()->timezone)->setTime(12, 0)->utc()->toIso8601ZuluString(),
                'href' => "/farms/{$c->farm_id}/crops/cycles/{$c->id}",
            ])
            ->all();
    }

    /**
     * Expected against harvested yield per cycle, for cycles harvested in the
     * period (at most 12).
     *
     * @return array{labels:array<int,string>, expected:array<int,float>, actual:array<int,float>}
     */
    public function expectedVsActual(Period $period): array
    {
        $cycleIds = $this->harvestsIn($period)->pluck('cycle_id')->unique()->take(12);
        $cycles = CropCycle::with('harvests', 'plot')->whereIn('id', $cycleIds)->orderBy('code')->get();

        return [
            'labels' => $cycles->map(fn ($c) => "{$c->code} ({$c->plot->code})")->all(),
            'expected' => $cycles->map(fn ($c) => round($this->units->toKg((float) $c->expected_yield, $c->yield_unit) ?? 0, 1))->all(),
            'actual' => $cycles->map(fn ($c) => round($c->harvests->sum(fn ($h) => $this->units->toKg((float) $h->quantity, $h->unit) ?? 0), 1))->all(),
        ];
    }

    private function harvestsIn(Period $period)
    {
        $tz = $this->context->farm()->timezone;

        return CropHarvest::whereBetween('harvested_on', [$period->from->setTimezone($tz)->toDateString(), $period->to->setTimezone($tz)->toDateString()])
            ->get(['cycle_id', 'quantity', 'unit']);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->context->farm()->timezone)->startOfDay();
    }
}
