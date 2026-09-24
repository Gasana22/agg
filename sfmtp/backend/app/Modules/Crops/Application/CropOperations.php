<?php

namespace App\Modules\Crops\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Crops\Domain\Enums\OperationStatus;
use App\Modules\Crops\Domain\Models\CropCycle;
use App\Modules\Crops\Domain\Models\CropObservation;
use App\Modules\Crops\Domain\Models\CropOperation;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Field operations. Work recorded by someone who may verify it
 * (`crops.operations.approve`) is verified at once; anyone else's waits for
 * verification. Only verified work reaches the trace history and starts the
 * withholding clock of the inputs used (docs/07 §2).
 */
class CropOperations
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly AuditLogger $audit,
        private readonly CropAccess $access,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string,mixed>  $data  validated, with `inputs`
     */
    public function record(CropCycle $cycle, array $data): CropOperation
    {
        $this->access->assertCanRecordOn('crops.operations.record', ['crop_cycle' => $cycle->id, 'plot' => $cycle->plot_id]);
        $this->access->assertNoMoneyUnlessAllowed($data, ['cost_amount']);
        if (! $cycle->isOpen()) {
            throw ApiException::conflict('cycle_closed', 'The crop cycle is closed.');
        }
        if (! empty($data['observation_id']) && ! CropObservation::whereKey($data['observation_id'])->where('cycle_id', $cycle->id)->exists()) {
            throw $this->invalid('observation_id', 'The observation must belong to this crop cycle.');
        }
        $inputs = $data['inputs'] ?? [];
        foreach ($inputs as $i => $input) {
            if (! empty($input['input_batch_id'])) {
                $batch = TraceBatch::find($input['input_batch_id']);
                if ($batch === null || ! in_array($batch->kind, [BatchKind::InputLot, BatchKind::SeedLot], true)) {
                    throw $this->invalid("inputs.{$i}.input_batch_id", 'Choose an input or seed lot of this farm.');
                }
            }
        }

        return DB::transaction(function () use ($cycle, $data, $inputs) {
            $verifyNow = $this->access->can('crops.operations.approve');
            $operation = CropOperation::create([
                'cycle_id' => $cycle->id,
                'observation_id' => $data['observation_id'] ?? null,
                'type' => $data['type'],
                'occurred_at' => $data['occurred_at'] ?? now(),
                'status' => OperationStatus::Recorded,
                'notes' => $data['notes'] ?? null,
                'labour_hours' => $data['labour_hours'] ?? null,
                'cost_amount' => $data['cost_amount'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'recorded_by' => Auth::id(),
            ]);
            foreach ($inputs as $input) {
                $operation->inputs()->create(array_intersect_key($input, array_flip(['input_batch_id', 'product_name', 'quantity', 'unit', 'withholding_days'])));
            }
            $this->audit->record('crops.operation.recorded', $operation, null, ['cycle' => $cycle->code, 'type' => $operation->type->value, 'inputs' => count($inputs)]);

            if ($verifyNow) {
                $this->markVerified($operation->load('inputs', 'cycle'));
            }

            return $operation->refresh()->load('inputs');
        });
    }

    public function verify(CropOperation $operation): CropOperation
    {
        if ($operation->status !== OperationStatus::Recorded) {
            throw ApiException::conflict('invalid_state_transition', "The operation is already {$operation->status->value}.");
        }
        if ($operation->recorded_by === Auth::id() && ! $this->context->membership()?->is_owner) {
            throw ApiException::forbidden('four_eyes', 'Someone other than the person who recorded it must verify it.');
        }

        return DB::transaction(function () use ($operation) {
            $this->markVerified($operation->load('inputs', 'cycle'));
            $this->audit->record('crops.operation.verified', $operation, ['status' => 'recorded'], ['status' => 'verified']);

            return $operation->refresh()->load('inputs');
        });
    }

    public function reject(CropOperation $operation, string $reason): CropOperation
    {
        if ($operation->status !== OperationStatus::Recorded) {
            throw ApiException::conflict('invalid_state_transition', "The operation is already {$operation->status->value}.");
        }
        $operation->forceFill(['status' => OperationStatus::Rejected, 'verified_by' => Auth::id(), 'verified_at' => now(), 'rejection_reason' => $reason])->save();
        $this->audit->record('crops.operation.rejected', $operation, ['status' => 'recorded'], ['status' => 'rejected', 'reason' => $reason]);

        return $operation->load('inputs');
    }

    private function markVerified(CropOperation $operation): void
    {
        $operation->forceFill(['status' => OperationStatus::Verified, 'verified_by' => Auth::id(), 'verified_at' => now()])->save();
        $cycle = $operation->cycle;
        $batch = $cycle->workingBatch();

        $event = [
            'occurred_at' => $operation->occurred_at,
            'plot_id' => $cycle->plot_id,
            'latitude' => $operation->latitude,
            'longitude' => $operation->longitude,
            'subject_type' => 'crop_operation',
            'subject_id' => $operation->id,
        ];
        // No money in trace payloads: they may become public (docs/07 §5).
        $this->recorder->record($batch, 'operation', $event + ['payload' => array_filter([
            'operation' => $operation->type->value,
            'labour_hours' => $operation->labour_hours,
            'notes' => $operation->notes,
            'treats' => $operation->observation_id,
        ], fn ($v) => $v !== null)]);

        $longest = 0;
        foreach ($operation->inputs as $input) {
            $this->recorder->record($batch, 'input_applied', $event + ['payload' => array_filter([
                'product' => $input->product_name,
                'quantity' => $input->quantity,
                'unit' => $input->unit,
                'withholding_days' => $input->withholding_days,
                'input_batch' => $input->input_batch_id ? $input->inputBatch?->batch_code : null,
            ], fn ($v) => $v !== null)]);
            $longest = max($longest, (int) $input->withholding_days);
        }

        if ($longest > 0) {
            $safe = CarbonImmutable::parse($operation->occurred_at)->setTimezone($this->context->farm()->timezone)->startOfDay()->addDays($longest);
            if ($cycle->safe_harvest_on === null || $safe->greaterThan($cycle->safe_harvest_on)) {
                $cycle->forceFill(['safe_harvest_on' => $safe->toDateString()])->saveQuietly();
            }
        }
    }

    private function invalid(string $field, string $message): ApiException
    {
        return ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => [$message]]);
    }
}
