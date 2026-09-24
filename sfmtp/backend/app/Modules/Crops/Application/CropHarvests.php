<?php

namespace App\Modules\Crops\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Crops\Domain\Enums\CycleStage;
use App\Modules\Crops\Domain\Models\CropCycle;
use App\Modules\Crops\Domain\Models\CropHarvest;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Enums\LinkType;
use App\Support\Http\ApiException;
use App\Support\Time\EventTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Harvests: each creates a harvest batch derived from the crop lot. A harvest
 * inside an input's withholding period is refused unless someone who may
 * verify crop work overrides it with a reason, which is kept on the record
 * and in the trace history.
 */
class CropHarvests
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly AuditLogger $audit,
        private readonly CropAccess $access,
        private readonly CropCycles $cycles,
    ) {}

    public function record(CropCycle $cycle, array $data): CropHarvest
    {
        $this->access->assertCanRecordOn('crops.harvest.record');
        if (! in_array($cycle->stage->value, CycleStage::inField(), true) || $cycle->cropLot === null) {
            throw ApiException::conflict('invalid_state_transition', 'Only a crop in the field can be harvested.');
        }

        $harvestedOn = CarbonImmutable::parse($data['harvested_on']);
        if ($cycle->planted_on && $harvestedOn->lessThan($cycle->planted_on)) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['harvested_on' => ['The harvest date is before planting.']]);
        }
        $override = $data['withholding_override_reason'] ?? null;
        if ($cycle->safe_harvest_on && $harvestedOn->lessThan($cycle->safe_harvest_on)) {
            if (! $override) {
                throw new ApiException(422, 'withholding_period', "A treatment's withholding period runs until {$cycle->safe_harvest_on->toDateString()}. Harvest after it, or record an override reason.", [
                    'safe_harvest_on' => $cycle->safe_harvest_on->toDateString(),
                ]);
            }
            if (! $this->access->can('crops.operations.approve')) {
                throw ApiException::forbidden('override_not_allowed', 'Only someone who verifies crop work can override a withholding period.');
            }
        } else {
            $override = null;
        }

        return DB::transaction(function () use ($cycle, $data, $harvestedOn, $override) {
            $quantity = (string) $data['quantity'];
            $event = ['occurred_at' => EventTime::on($harvestedOn, 12), 'plot_id' => $cycle->plot_id, 'subject_type' => 'crop_cycle', 'subject_id' => $cycle->id];

            $batch = $this->recorder->createBatch(BatchKind::Harvest, [
                'name' => mb_substr("{$cycle->crop->label()} harvest · Plot {$cycle->plot->code} ({$cycle->code})", 0, 150),
                'quantity' => $quantity,
                'unit' => $data['unit'],
                'origin_plot_id' => $cycle->plot_id,
                'source_type' => 'crop_cycle',
                'source_id' => $cycle->id,
            ], $event);
            $this->recorder->link($cycle->cropLot, $batch, LinkType::Derived, $quantity, $data['unit']);
            $this->recorder->record($batch, 'harvested', $event + ['payload' => array_filter([
                'cycle' => $cycle->code,
                'quantity' => $quantity,
                'unit' => $data['unit'],
                'quality_grade' => $data['quality_grade'] ?? null,
                'moisture_pct' => $data['moisture_pct'] ?? null,
                'withholding_override' => $override,
            ], fn ($v) => $v !== null)]);

            $harvest = CropHarvest::create([
                'cycle_id' => $cycle->id,
                'harvested_on' => $harvestedOn->toDateString(),
                'quantity' => $quantity,
                'unit' => $data['unit'],
                'quality_grade' => $data['quality_grade'] ?? null,
                'moisture_pct' => $data['moisture_pct'] ?? null,
                'notes' => $data['notes'] ?? null,
                'trace_batch_id' => $batch->id,
                'withholding_override_reason' => $override,
                'recorded_by' => Auth::id(),
            ]);

            if ($cycle->stage !== CycleStage::Harvesting) {
                $this->cycles->advance($cycle, CycleStage::Harvesting, 'First harvest recorded');
            }
            $this->audit->record('crops.harvest.recorded', $harvest, null, ['cycle' => $cycle->code, 'quantity' => $quantity, 'unit' => $data['unit'], 'batch' => $batch->batch_code] + ($override ? ['withholding_override' => $override] : []));

            return $harvest->load('batch');
        });
    }
}
