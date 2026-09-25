<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Crops\Domain\Enums\CycleStage;
use App\Modules\Crops\Domain\Enums\ObservationStatus;
use App\Modules\Crops\Domain\Models\CropCycle;
use App\Modules\Crops\Domain\Models\CropObservation;
use App\Modules\Livestock\Domain\Enums\AnimalStatus;
use App\Modules\Livestock\Domain\Models\Animal;
use App\Modules\Livestock\Domain\Models\HealthRecord;
use App\Modules\Livestock\Domain\Models\Weight;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Crop and animal health scores (docs/05 §4, ADR-0017). Every open crop
 * cycle and every active animal starts at 100 and loses points for what is
 * wrong with it today; the farm score is the average. The rules are simple
 * on purpose, so a farmer can tell why a score dropped: each deduction
 * comes with its reason.
 */
class HealthScores
{
    /** Points lost per open pest or disease incident, by severity. */
    public const CROP_DEDUCTIONS = ['low' => 5, 'medium' => 15, 'high' => 30, 'critical' => 50];

    /** Points lost per animal problem. */
    public const ANIMAL_DEDUCTIONS = ['vaccination_overdue' => 20, 'weight_loss' => 25, 'recent_treatment' => 15];

    private ?array $crops = null;

    private ?array $animals = null;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<int, array{id:string, code:string, crop:?string, plot:?string, lat:?float, lng:?float,
     *                          score:int, band:string, incidents:int, reasons:array<int,string>}> worst first
     */
    public function cropCycles(): array
    {
        if ($this->crops !== null) {
            return $this->crops;
        }

        $incidents = CropObservation::whereIn('kind', ['pest', 'disease'])
            ->where('status', '!=', ObservationStatus::Resolved->value)
            ->get(['cycle_id', 'severity', 'title'])
            ->groupBy('cycle_id');

        $rows = CropCycle::with('crop', 'plot')->where('stage', '!=', CycleStage::Closed->value)->orderBy('code')->get()
            ->map(function (CropCycle $c) use ($incidents) {
                $open = $incidents[$c->id] ?? collect();
                $lost = $open->sum(fn ($o) => self::CROP_DEDUCTIONS[$o->severity->value]);
                $score = max(0, 100 - $lost);

                return [
                    'id' => $c->id,
                    'code' => $c->code,
                    'crop' => $c->crop?->label(),
                    'plot' => $c->plot?->code,
                    'lat' => $c->plot?->centroid_lat === null ? null : (float) $c->plot->centroid_lat,
                    'lng' => $c->plot?->centroid_lng === null ? null : (float) $c->plot->centroid_lng,
                    'score' => $score,
                    'band' => self::band($score),
                    'incidents' => $open->count(),
                    'reasons' => $open->map(fn ($o) => ucfirst($o->severity->value).': '.$o->title)->values()->all(),
                ];
            })
            ->sortBy([['score', 'asc'], ['code', 'asc']])
            ->values()
            ->all();

        return $this->crops = $rows;
    }

    /** Average over open cycles, or null without any. */
    public function cropScore(): ?float
    {
        $rows = $this->cropCycles();

        return $rows === [] ? null : round(array_sum(array_column($rows, 'score')) / count($rows), 1);
    }

    /**
     * @return array<int, array{id:string, code:string, label:string, score:int, band:string, reasons:array<int,string>}> worst first
     */
    public function animals(): array
    {
        if ($this->animals !== null) {
            return $this->animals;
        }

        $animals = Animal::where('status', AnimalStatus::Active->value)->orderBy('animal_code')->get();
        $byGroup = $animals->groupBy('group_id');
        $problems = [];
        $flag = function (?string $animalId, ?string $groupId, string $reason) use (&$problems, $byGroup) {
            $ids = $animalId !== null ? [$animalId] : ($byGroup[$groupId] ?? collect())->pluck('id')->all();
            foreach ($ids as $id) {
                $problems[$id][$reason] = true;
            }
        };

        $today = $this->today();

        // The latest dose of each vaccine or dewormer, per animal or group, is overdue.
        HealthRecord::notVoided()->whereIn('kind', ['vaccination', 'deworming'])
            ->where('given_on', '>=', $today->subDays(400)->toDateString())
            ->orderBy('given_on')->get(['animal_id', 'group_id', 'kind', 'product_name', 'given_on', 'next_due_on'])
            ->groupBy(fn ($r) => implode('|', [$r->kind->value, $r->product_name, $r->animal_id ?? 'g:'.$r->group_id]))
            ->each(function ($doses) use ($flag, $today) {
                $last = $doses->last();
                if ($last->next_due_on !== null && $last->next_due_on->lessThan($today) && $last->next_due_on->greaterThanOrEqualTo($today->subDays(60))) {
                    $flag($last->animal_id, $last->group_id, 'vaccination_overdue');
                }
            });

        HealthRecord::notVoided()->whereIn('kind', ['treatment', 'injury'])
            ->where('given_on', '>=', $today->subDays(30)->toDateString())
            ->get(['animal_id', 'group_id'])
            ->each(fn ($r) => $flag($r->animal_id, $r->group_id, 'recent_treatment'));

        Weight::notVoided()->where('weighed_on', '>=', $today->subDays(120)->toDateString())->orderBy('weighed_on')
            ->get(['animal_id', 'weighed_on', 'weight_kg'])->groupBy('animal_id')
            ->each(function ($weights, $animalId) use ($flag) {
                if ($weights->count() < 2) {
                    return;
                }
                [$prev, $last] = [(float) $weights[$weights->count() - 2]->weight_kg, (float) $weights->last()->weight_kg];
                if ($prev > 0 && ($prev - $last) / $prev >= 0.05) {
                    $flag($animalId, null, 'weight_loss');
                }
            });

        $labels = ['vaccination_overdue' => 'Vaccination or deworming overdue', 'weight_loss' => 'Lost 5% or more weight', 'recent_treatment' => 'Treated in the last 30 days'];

        $rows = $animals->map(function (Animal $a) use ($problems, $labels) {
            $reasons = array_keys(array_intersect_key(self::ANIMAL_DEDUCTIONS, $problems[$a->id] ?? []));
            $score = max(0, 100 - array_sum(array_map(fn ($r) => self::ANIMAL_DEDUCTIONS[$r], $reasons)));

            return ['id' => $a->id, 'code' => $a->animal_code, 'label' => $a->label(), 'score' => $score, 'band' => self::band($score),
                'reasons' => array_map(fn ($r) => $labels[$r], $reasons)];
        })->sortBy([['score', 'asc'], ['code', 'asc']])->values()->all();

        return $this->animals = $rows;
    }

    public function animalScore(): ?float
    {
        $rows = $this->animals();

        return $rows === [] ? null : round(array_sum(array_column($rows, 'score')) / count($rows), 1);
    }

    /** @return array{good:int, watch:int, poor:int} */
    public static function bands(array $rows): array
    {
        $out = ['good' => 0, 'watch' => 0, 'poor' => 0];
        foreach ($rows as $r) {
            $out[$r['band']]++;
        }

        return $out;
    }

    public static function band(float $score): string
    {
        return $score >= 80 ? 'good' : ($score >= 50 ? 'watch' : 'poor');
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->context->farm()->timezone)->startOfDay();
    }
}
