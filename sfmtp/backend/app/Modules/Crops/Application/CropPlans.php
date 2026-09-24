<?php

namespace App\Modules\Crops\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Crops\Domain\Enums\CycleStage;
use App\Modules\Crops\Domain\Enums\PlanStatus;
use App\Modules\Crops\Domain\Models\Crop;
use App\Modules\Crops\Domain\Models\CropPlan;
use App\Modules\Crops\Domain\Models\Season;
use App\Modules\Tenancy\TenantContext;
use App\Support\Database\Codes;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\Auth;

/**
 * Crop plans: draft → approved → active (when its first cycle starts) →
 * closed. Only drafts can be edited. The owner or anyone holding
 * `crops.plans.approve` approves, but never their own plan unless they are
 * the owner (four-eyes, docs/04 §4).
 */
class CropPlans
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function create(array $data): CropPlan
    {
        $this->assertReferences($data);
        $plan = new CropPlan($data + ['yield_unit' => $this->defaultUnit($data['crop_id'])]);
        $plan->code = Codes::next(CropPlan::class, 'CP');
        $plan->status = PlanStatus::Draft;
        $plan->created_by = Auth::id();
        $plan->save();

        $this->audit->record('crops.plan.created', $plan, null, $this->audited($plan));

        return $plan->refresh();
    }

    public function update(CropPlan $plan, array $data): CropPlan
    {
        if ($plan->status !== PlanStatus::Draft) {
            throw ApiException::conflict('invalid_state_transition', 'Only draft plans can be edited.');
        }
        $this->assertReferences($data);
        $before = $this->audited($plan);
        $plan->fill($data)->save();
        if ($plan->wasChanged()) {
            $this->audit->record('crops.plan.updated', $plan, $before, $this->audited($plan));
        }

        return $plan;
    }

    public function approve(CropPlan $plan): CropPlan
    {
        if ($plan->status !== PlanStatus::Draft) {
            throw ApiException::conflict('invalid_state_transition', "The plan is {$plan->status->value}.");
        }
        $isOwner = (bool) $this->context->membership()?->is_owner;
        if ($plan->created_by === Auth::id() && ! $isOwner) {
            throw ApiException::forbidden('four_eyes', 'Someone other than the plan author must approve it.');
        }

        $plan->forceFill(['status' => PlanStatus::Approved, 'approved_by' => Auth::id(), 'approved_at' => now()])->save();
        $this->audit->record('crops.plan.approved', $plan, ['status' => 'draft'], ['status' => 'approved']);

        return $plan;
    }

    public function close(CropPlan $plan): CropPlan
    {
        if ($plan->status === PlanStatus::Closed) {
            throw ApiException::conflict('invalid_state_transition', 'The plan is already closed.');
        }
        if ($plan->cycles()->where('stage', '!=', CycleStage::Closed->value)->exists()) {
            throw ApiException::conflict('has_open_cycles', 'Close the crop cycles of this plan first.');
        }

        $before = $plan->status->value;
        $plan->forceFill(['status' => PlanStatus::Closed, 'closed_at' => now()])->save();
        $this->audit->record('crops.plan.closed', $plan, ['status' => $before], ['status' => 'closed']);

        return $plan;
    }

    /** A cycle started against the plan makes an approved plan active. */
    public function activate(CropPlan $plan): void
    {
        if ($plan->status === PlanStatus::Approved) {
            $plan->forceFill(['status' => PlanStatus::Active])->save();
        }
    }

    private function assertReferences(array $data): void
    {
        $errors = [];
        if (isset($data['season_id']) && ! Season::whereKey($data['season_id'])->exists()) {
            $errors['season_id'] = ['The selected season does not exist in this farm.'];
        }
        if (isset($data['crop_id']) && ! Crop::whereKey($data['crop_id'])->where('is_active', true)->exists()) {
            $errors['crop_id'] = ['The selected crop is not on this farm\'s crop list.'];
        }
        if ($errors) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', $errors);
        }
    }

    private function defaultUnit(string $cropId): string
    {
        return Crop::whereKey($cropId)->value('yield_unit') ?? 'kg';
    }

    private function audited(CropPlan $plan): array
    {
        return $plan->only(['code', 'name', 'season_id', 'crop_id', 'planned_area_ha', 'expected_yield', 'yield_unit', 'budget_amount']);
    }
}
