<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Catalog\Application\Units;
use App\Modules\Livestock\Domain\Enums\AnimalStatus;
use App\Modules\Livestock\Domain\Models\Animal;
use App\Modules\Livestock\Domain\Models\Breeding;
use App\Modules\Livestock\Domain\Models\HealthRecord;
use App\Modules\Livestock\Domain\Models\ProductionRecord;
use App\Modules\Livestock\Domain\Models\SaleRequest;
use App\Modules\Livestock\Domain\Models\Weight;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Livestock metrics for the livestock, owner and manager dashboards (docs/05 §3.5). */
class LivestockMetrics
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Units $units,
    ) {}

    public function headCount(): int
    {
        return Animal::where('status', AnimalStatus::Active->value)->count();
    }

    /** @return array<string,int> active animals per species name */
    public function bySpecies(): array
    {
        return Animal::where('status', AnimalStatus::Active->value)
            ->join('global_animal_species as s', 's.id', '=', 'animals.species_id')
            ->groupBy('s.name')->orderBy('s.name')
            ->select('s.name', DB::raw('COUNT(*) AS n'))
            ->pluck('n', 'name')->map(fn ($n) => (int) $n)->all();
    }

    public function newAnimals(Period $p): int
    {
        [$from, $to] = $this->days($p);

        return Animal::where(fn ($q) => $q->whereBetween('birth_date', [$from, $to])->where('origin', 'born'))
            ->orWhere(fn ($q) => $q->whereBetween('acquired_on', [$from, $to])->where('origin', '!=', 'born'))
            ->count();
    }

    public function pregnant(): int
    {
        return Breeding::where('status', 'pregnant')->count();
    }

    public function underWithdrawal(): int
    {
        $today = $this->today()->toDateString();

        return Animal::where('status', AnimalStatus::Active->value)
            ->where(fn ($q) => $q->where('meat_withdrawal_until', '>=', $today)->orWhere('milk_withdrawal_until', '>=', $today))
            ->count();
    }

    /** Deaths in the period as a share of the herd at risk (a fraction; the dashboard formats it). */
    public function mortalityRate(Period $p): ?float
    {
        [$from, $to] = $this->days($p);
        $deaths = Animal::where('status', AnimalStatus::Dead->value)->whereBetween('exited_on', [$from, $to])->count();
        $atRisk = $this->headCount() + $deaths;

        return $atRisk === 0 ? null : round($deaths / $atRisk, 4);
    }

    public function sold(Period $p): int
    {
        [$from, $to] = $this->days($p);

        return SaleRequest::where('status', 'completed')->whereBetween('sold_on', [$from, $to])->count();
    }

    /** Litres of milk kept (not discarded) in the period. */
    public function milkLitres(Period $p): float
    {
        [$from, $to] = $this->days($p);

        return round(ProductionRecord::notVoided()->where('product', 'milk')->where('discarded', false)
            ->whereBetween('produced_on', [$from, $to])->get(['quantity', 'unit'])
            ->sum(fn ($r) => $this->units->convert((float) $r->quantity, $r->unit, 'l') ?? 0), 1);
    }

    public function milkToday(): float
    {
        $today = $this->today()->toDateString();

        return round(ProductionRecord::notVoided()->where('product', 'milk')->where('discarded', false)->where('produced_on', $today)
            ->get(['quantity', 'unit'])->sum(fn ($r) => $this->units->convert((float) $r->quantity, $r->unit, 'l') ?? 0), 1);
    }

    public function eggsToday(): int
    {
        return (int) ProductionRecord::notVoided()->where('product', 'eggs')->where('discarded', false)->where('produced_on', $this->today()->toDateString())
            ->get(['quantity', 'unit'])->sum(fn ($r) => $this->units->convert((float) $r->quantity, $r->unit, 'pcs') ?? 0);
    }

    /** Average daily gain (kg/day) over animals weighed at least twice in 90 days. */
    public function averageDailyGain(): ?float
    {
        $since = $this->today()->subDays(90)->toDateString();
        $gains = Weight::notVoided()->where('weighed_on', '>=', $since)->orderBy('weighed_on')->get(['animal_id', 'weighed_on', 'weight_kg'])
            ->groupBy('animal_id')
            ->filter(fn ($w) => $w->count() >= 2 && $w->last()->weighed_on->greaterThan($w->first()->weighed_on))
            ->map(fn ($w) => ((float) $w->last()->weight_kg - (float) $w->first()->weight_kg) / $w->first()->weighed_on->diffInDays($w->last()->weighed_on));

        return $gains->isEmpty() ? null : round($gains->avg(), 2);
    }

    /** @return array<int,array> vaccinations and dewormings due in 14 days, or overdue, not yet repeated */
    public function vaccinationsDue(int $days = 14): array
    {
        $today = $this->today();
        $records = HealthRecord::notVoided()->with('animal', 'group')
            ->whereIn('kind', ['vaccination', 'deworming'])
            ->whereNotNull('next_due_on')
            ->where('next_due_on', '<=', $today->addDays($days)->toDateString())
            ->where('next_due_on', '>=', $today->subDays(60)->toDateString())
            ->orderBy('next_due_on')->get();

        // Drop doses already given again since.
        $records = $records->reject(fn (HealthRecord $r) => HealthRecord::notVoided()->where('kind', $r->kind)->where('product_name', $r->product_name)
            ->where(fn ($q) => $r->animal_id ? $q->where('animal_id', $r->animal_id) : $q->where('group_id', $r->group_id))
            ->where('given_on', '>', $r->given_on)->exists())
            ->reject(fn (HealthRecord $r) => $r->animal && $r->animal->status !== AnimalStatus::Active);

        return $records->take(8)->map(fn (HealthRecord $r) => [
            'id' => $r->id,
            'title' => ($r->product_name ?? ucfirst($r->kind->value)).' · '.($r->animal?->animal_code ?? $r->group?->name),
            'subtitle' => ucfirst($r->kind->value).', last given '.$r->given_on->toDateString(),
            'at' => $r->next_due_on->setTime(9, 0)->toIso8601ZuluString(),
            'badge' => $r->next_due_on->lessThan($today) ? ['label' => 'Overdue', 'tone' => 'danger'] : ['label' => 'Due', 'tone' => 'warning'],
            'href' => $r->animal_id ? "/farms/{$r->farm_id}/livestock/animals/{$r->animal_id}" : "/farms/{$r->farm_id}/livestock?tab=groups",
        ])->values()->all();
    }

    /** @return array<int,array> animals under a meat or milk withdrawal period */
    public function withdrawalAlerts(): array
    {
        $today = $this->today()->toDateString();

        return Animal::where('status', AnimalStatus::Active->value)
            ->where(fn ($q) => $q->where('meat_withdrawal_until', '>=', $today)->orWhere('milk_withdrawal_until', '>=', $today))
            ->orderBy('animal_code')->limit(8)->get()
            ->map(fn (Animal $a) => [
                'id' => $a->id,
                'title' => $a->label(),
                'subtitle' => implode(' · ', array_filter([
                    $a->milk_withdrawal_until && $a->milk_withdrawal_until->toDateString() >= $today ? 'no milk until '.$a->milk_withdrawal_until->toDateString() : null,
                    $a->meat_withdrawal_until && $a->meat_withdrawal_until->toDateString() >= $today ? 'no slaughter until '.$a->meat_withdrawal_until->toDateString() : null,
                ])),
                'badge' => ['label' => 'Withdrawal', 'tone' => 'warning'],
                'href' => "/farms/{$a->farm_id}/livestock/animals/{$a->id}",
            ])->all();
    }

    /** @return array<int,array> births expected in the next 30 days */
    public function expectedBirths(int $days = 30): array
    {
        $today = $this->today();

        return Breeding::with('dam')->where('status', 'pregnant')
            ->whereBetween('expected_due_on', [$today->subDays(14)->toDateString(), $today->addDays($days)->toDateString()])
            ->orderBy('expected_due_on')->limit(8)->get()
            ->map(fn (Breeding $b) => [
                'id' => $b->id,
                'title' => $b->dam->label(),
                'subtitle' => 'Served '.$b->served_on->toDateString().' · '.($b->method->value === 'ai' ? 'AI' : 'natural'),
                'at' => $b->expected_due_on->setTime(9, 0)->toIso8601ZuluString(),
                'href' => "/farms/{$b->farm_id}/livestock/animals/{$b->dam_id}",
            ])->all();
    }

    /** @return array<int,array> animals whose latest weight fell 5% or more */
    public function weightLoss(): array
    {
        $out = [];
        $since = $this->today()->subDays(120)->toDateString();
        foreach (Weight::notVoided()->with('animal')->where('weighed_on', '>=', $since)->orderBy('weighed_on')->get()->groupBy('animal_id') as $weights) {
            if ($weights->count() < 2 || ! $weights->last()->animal?->isActive()) {
                continue;
            }
            [$prev, $last] = [$weights[$weights->count() - 2], $weights->last()];
            $drop = ((float) $prev->weight_kg - (float) $last->weight_kg) / (float) $prev->weight_kg;
            if ($drop >= 0.05) {
                $out[] = [
                    'id' => $last->id,
                    'title' => $last->animal->label(),
                    'subtitle' => sprintf('%s kg → %s kg (−%d%%)', (float) $prev->weight_kg, (float) $last->weight_kg, round($drop * 100)),
                    'at' => $last->weighed_on->setTime(9, 0)->toIso8601ZuluString(),
                    'badge' => ['label' => 'Weight loss', 'tone' => 'danger'],
                    'href' => "/farms/{$last->farm_id}/livestock/animals/{$last->animal_id}",
                ];
            }
        }

        return array_slice($out, 0, 8);
    }

    /** @return array<int,array> sale requests waiting for a decision */
    public function saleRequestsPending(): array
    {
        return SaleRequest::with('animal')->where('status', 'requested')->orderBy('created_at')->limit(8)->get()
            ->map(fn (SaleRequest $s) => [
                'id' => $s->id,
                'title' => "{$s->code} · {$s->animal->label()}",
                'subtitle' => $s->reason ?? 'Sale request',
                'at' => $s->created_at->toIso8601ZuluString(),
                'href' => "/farms/{$s->farm_id}/livestock?tab=sales",
            ])->all();
    }

    /** @return array<int,array{date:string,litres:float}> kept milk per local day */
    public function milkPerDay(Period $p): array
    {
        [$from, $to] = $this->days($p);
        $byDay = ProductionRecord::notVoided()->where('product', 'milk')->where('discarded', false)
            ->whereBetween('produced_on', [$from, $to])->get(['produced_on', 'quantity', 'unit'])
            ->groupBy(fn ($r) => $r->produced_on->toDateString())
            ->map(fn ($rows) => $rows->sum(fn ($r) => $this->units->convert((float) $r->quantity, $r->unit, 'l') ?? 0));

        $series = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $series[] = ['date' => $d->toDateString(), 'litres' => round((float) ($byDay[$d->toDateString()] ?? 0), 1)];
        }

        return $series;
    }

    /** @return array{0:string,1:string} the period as local dates */
    private function days(Period $p): array
    {
        $tz = $this->context->farm()->timezone;

        return [$p->from->setTimezone($tz)->toDateString(), $p->to->setTimezone($tz)->toDateString()];
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->context->farm()->timezone)->startOfDay();
    }
}
