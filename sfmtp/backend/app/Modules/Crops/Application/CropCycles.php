<?php

namespace App\Modules\Crops\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Crops\Domain\Enums\CloseReason;
use App\Modules\Crops\Domain\Enums\CycleStage;
use App\Modules\Crops\Domain\Enums\PlanStatus;
use App\Modules\Crops\Domain\Enums\PlantingMethod;
use App\Modules\Crops\Domain\Models\Crop;
use App\Modules\Crops\Domain\Models\CropCycle;
use App\Modules\Crops\Domain\Models\CropPlan;
use App\Modules\FarmStructure\Domain\Models\Plot;
use App\Modules\Tenancy\Application\FarmSettings;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Enums\BatchStatus;
use App\Modules\Traceability\Domain\Enums\LinkType;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Support\Database\Codes;
use App\Support\Http\ApiException;
use App\Support\Time\EventTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The crop cycle lifecycle and its traceability (docs/07 §2):
 *
 *   direct:     seed lot ─derived→ crop lot (event `planted`)
 *   transplant: seed lot ─derived→ nursery (event `sown`)
 *               nursery ─derived→ crop lot at transplanting (event `planted`)
 *
 * Every step runs in one transaction with its trace writes.
 */
class CropCycles
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly FarmSettings $settings,
        private readonly CropPlans $plans,
    ) {}

    /**
     * @param  array<string,mixed>  $data  validated
     * @return array{0: CropCycle, 1: array<int,array{code:string,message:string}>} the cycle and warnings
     */
    public function start(array $data): array
    {
        $plot = Plot::find($data['plot_id']) ?? throw $this->invalid('plot_id', 'The selected plot does not exist in this farm.');
        $crop = Crop::whereKey($data['crop_id'])->where('is_active', true)->first() ?? throw $this->invalid('crop_id', 'The selected crop is not on this farm\'s crop list.');
        $plan = null;
        if (! empty($data['plan_id'])) {
            $plan = CropPlan::find($data['plan_id']) ?? throw $this->invalid('plan_id', 'The selected plan does not exist in this farm.');
            if (! in_array($plan->status, [PlanStatus::Approved, PlanStatus::Active], true)) {
                throw ApiException::unprocessable('plan_not_approved', 'Only approved plans can have crop cycles.');
            }
            if ($plan->crop_id !== $crop->id) {
                throw $this->invalid('crop_id', 'The crop must match the plan\'s crop.');
            }
        }
        $seed = null;
        if (! empty($data['seed_batch_id'])) {
            $seed = TraceBatch::find($data['seed_batch_id']);
            if ($seed === null || $seed->kind !== BatchKind::SeedLot) {
                throw $this->invalid('seed_batch_id', 'Choose a seed lot of this farm.');
            }
        }

        $method = PlantingMethod::from($data['planting_method'] ?? PlantingMethod::Direct->value);
        $warnings = [];

        return DB::transaction(function () use ($data, $plot, $crop, $plan, $seed, $method, &$warnings) {
            // One open cycle per plot unless the farm allows intercropping.
            DB::table('farm_plots')->where('id', $plot->id)->lockForUpdate()->first();
            $busy = CropCycle::where('plot_id', $plot->id)->where('stage', '!=', CycleStage::Closed->value)->first();
            if ($busy && ! ($this->settings->get($this->context->farm())['allow_intercropping'] ?? false)) {
                throw ApiException::conflict('plot_occupied', "Plot {$plot->code} already has an open crop cycle ({$busy->code}). Close it, or allow intercropping in farm settings.");
            }

            $area = (float) ($data['area_ha'] ?? $plot->effectiveAreaHa() ?? 0);
            if ($area <= 0) {
                throw $this->invalid('area_ha', 'Give the planted area; the plot has no mapped or declared area.');
            }
            if ($plot->effectiveAreaHa() !== null && $area > $plot->effectiveAreaHa() * 1.02) {
                $warnings[] = ['code' => 'area_exceeds_plot', 'message' => "The planted area is larger than plot {$plot->code} ({$plot->effectiveAreaHa()} ha)."];
            }

            $startDate = CarbonImmutable::parse($method === PlantingMethod::Transplant ? ($data['sown_on'] ?? 'today') : ($data['planted_on'] ?? 'today'));
            $cycle = new CropCycle([
                'plan_id' => $plan?->id,
                'season_id' => $plan?->season_id ?? ($data['season_id'] ?? null),
                'plot_id' => $plot->id,
                'crop_id' => $crop->id,
                'planting_method' => $method,
                'area_ha' => $area,
                'expected_yield' => $data['expected_yield'] ?? null,
                'yield_unit' => $data['yield_unit'] ?? $crop->yield_unit,
                'seeds_sown' => $data['seeds_sown'] ?? null,
                'seed_batch_id' => $seed?->id,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);
            $cycle->code = Codes::next(CropCycle::class, 'CC');
            $cycle->expected_harvest_on = $data['expected_harvest_on']
                ?? ($crop->maturity_days ? $startDate->addDays($crop->maturity_days)->toDateString() : null);

            $event = ['occurred_at' => EventTime::on($startDate, 8), 'plot_id' => $plot->id, 'subject_type' => 'crop_cycle'];

            if ($method === PlantingMethod::Transplant) {
                $cycle->stage = CycleStage::Nursery;
                $cycle->sown_on = $startDate->toDateString();
                $cycle->save();
                $nursery = $this->recorder->createBatch(BatchKind::Nursery, ['name' => $this->batchName('Nursery', $crop, $plot, $cycle), 'source_type' => 'crop_cycle', 'source_id' => $cycle->id],
                    $event + ['subject_id' => $cycle->id, 'payload' => ['cycle' => $cycle->code, 'seeds_sown' => $cycle->seeds_sown]]);
                if ($seed) {
                    $this->recorder->link($seed, $nursery, LinkType::Derived);
                }
                $this->recorder->record($nursery, 'sown', $event + ['subject_id' => $cycle->id, 'payload' => array_filter(['seeds_sown' => $cycle->seeds_sown])]);
                $cycle->forceFill(['nursery_batch_id' => $nursery->id])->saveQuietly();
            } else {
                $cycle->stage = CycleStage::Planted;
                $cycle->planted_on = $startDate->toDateString();
                $cycle->save();
                $lot = $this->createCropLot($cycle, $crop, $plot, $seed, $event);
                $cycle->forceFill(['crop_lot_batch_id' => $lot->id])->saveQuietly();
            }

            if ($plan) {
                $this->plans->activate($plan);
            }
            $this->audit->record('crops.cycle.started', $cycle, null, $cycle->only(['code', 'plot_id', 'crop_id', 'plan_id', 'area_ha', 'planting_method']));

            return [$cycle->refresh(), $warnings];
        });
    }

    /** Move seedlings from the nursery to the plot. */
    public function transplant(CropCycle $cycle, array $data): CropCycle
    {
        if ($cycle->stage !== CycleStage::Nursery) {
            throw ApiException::conflict('invalid_state_transition', 'Only cycles in the nursery can be transplanted.');
        }

        return DB::transaction(function () use ($cycle, $data) {
            $plantedOn = CarbonImmutable::parse($data['planted_on'] ?? 'today');
            $cycle->forceFill([
                'stage' => CycleStage::Planted,
                'planted_on' => $plantedOn->toDateString(),
                'seedlings_germinated' => $data['seedlings_germinated'] ?? $cycle->seedlings_germinated,
                'seedlings_transplanted' => $data['seedlings_transplanted'] ?? $cycle->seedlings_transplanted,
            ]);
            if (! empty($data['expected_harvest_on'])) {
                $cycle->expected_harvest_on = $data['expected_harvest_on'];
            }
            $cycle->save();

            $nursery = $cycle->nurseryBatch;
            $event = ['occurred_at' => EventTime::on($plantedOn, 8), 'plot_id' => $cycle->plot_id, 'subject_type' => 'crop_cycle', 'subject_id' => $cycle->id];
            $lot = $this->createCropLot($cycle, $cycle->crop, $cycle->plot, $nursery, $event, ['seedlings_transplanted' => $cycle->seedlings_transplanted]);
            if ($nursery && $nursery->status === BatchStatus::Open) {
                $this->recorder->changeStatus($nursery, BatchStatus::Closed, 'Transplanted');
            }
            $cycle->forceFill(['crop_lot_batch_id' => $lot->id])->saveQuietly();

            $this->audit->record('crops.cycle.transplanted', $cycle, ['stage' => 'nursery'], ['stage' => 'planted', 'planted_on' => $cycle->planted_on->toDateString()]);

            return $cycle->refresh();
        });
    }

    public function advance(CropCycle $cycle, CycleStage $to, ?string $note = null): CropCycle
    {
        if (! in_array($to, $cycle->stage->next(), true) || $to === CycleStage::Planted) {
            throw ApiException::conflict('invalid_state_transition', "A cycle cannot move from {$cycle->stage->value} to {$to->value}.");
        }

        return DB::transaction(function () use ($cycle, $to, $note) {
            $from = $cycle->stage;
            $cycle->forceFill(['stage' => $to])->save();
            $this->recorder->record($cycle->workingBatch(), 'stage_changed', [
                'plot_id' => $cycle->plot_id, 'subject_type' => 'crop_cycle', 'subject_id' => $cycle->id,
                'payload' => array_filter(['from' => $from->value, 'to' => $to->value, 'note' => $note]),
            ]);
            $this->audit->record('crops.cycle.stage_changed', $cycle, ['stage' => $from->value], ['stage' => $to->value]);

            return $cycle;
        });
    }

    public function close(CropCycle $cycle, CloseReason $reason, ?string $note, ?string $closedOn = null): CropCycle
    {
        if (! $cycle->isOpen()) {
            throw ApiException::conflict('invalid_state_transition', 'The cycle is already closed.');
        }
        if ($reason === CloseReason::Harvested && ! $cycle->harvests()->exists()) {
            throw ApiException::unprocessable('no_harvest', 'Record a harvest before closing the cycle as harvested.');
        }

        return DB::transaction(function () use ($cycle, $reason, $note, $closedOn) {
            $from = $cycle->stage;
            $cycle->forceFill(['stage' => CycleStage::Closed, 'closed_on' => $closedOn ?? now($this->context->farm()->timezone)->toDateString(), 'close_reason' => $reason])->save();
            foreach (array_filter([$cycle->cropLot, $cycle->nurseryBatch]) as $batch) {
                if ($batch->status === BatchStatus::Open) {
                    $this->recorder->changeStatus($batch, BatchStatus::Closed, trim("Cycle {$cycle->code} closed: {$reason->value}. {$note}"));
                }
            }
            $this->audit->record('crops.cycle.closed', $cycle, ['stage' => $from->value], ['stage' => 'closed', 'reason' => $reason->value]);

            return $cycle;
        });
    }

    /** Editable details; the plot, crop and planting method are fixed once started. */
    public function update(CropCycle $cycle, array $data): CropCycle
    {
        if (! $cycle->isOpen()) {
            throw ApiException::conflict('invalid_state_transition', 'Closed cycles cannot be edited.');
        }
        $before = $cycle->only(array_keys($data));
        $cycle->fill($data)->save();
        if ($cycle->wasChanged()) {
            $this->audit->record('crops.cycle.updated', $cycle, $before, $cycle->only(array_keys($data)));
        }

        return $cycle;
    }

    private function createCropLot(CropCycle $cycle, Crop $crop, Plot $plot, ?TraceBatch $parent, array $event, array $payload = []): TraceBatch
    {
        $lot = $this->recorder->createBatch(BatchKind::CropLot, [
            'name' => $this->batchName($crop->label(), $crop, $plot, $cycle),
            'origin_plot_id' => $plot->id,
            'source_type' => 'crop_cycle',
            'source_id' => $cycle->id,
        ], $event + ['subject_id' => $cycle->id, 'payload' => ['cycle' => $cycle->code, 'plot' => $plot->code, 'area_ha' => (string) $cycle->area_ha]]);

        if ($parent) {
            $this->recorder->link($parent, $lot, LinkType::Derived);
        }
        $this->recorder->record($lot, 'planted', $event + ['subject_id' => $cycle->id, 'payload' => array_filter(['crop' => $crop->label(), 'plot' => $plot->code, 'area_ha' => (string) $cycle->area_ha] + $payload)]);

        return $lot;
    }

    private function batchName(string $prefix, Crop $crop, Plot $plot, CropCycle $cycle): string
    {
        return mb_substr(($prefix === $crop->label() ? $prefix : "{$prefix} — {$crop->label()}")." · Plot {$plot->code} ({$cycle->code})", 0, 150);
    }

    private function invalid(string $field, string $message): ApiException
    {
        return ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => [$message]]);
    }
}
