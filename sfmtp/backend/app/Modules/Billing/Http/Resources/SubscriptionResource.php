<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Application\Usage;
use App\Modules\Billing\Domain\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $plan = $this->plan;

        return [
            'id' => $this->id,
            'type' => 'subscription',
            'organization' => $this->whenLoaded('organization', fn () => [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
                'owner' => $this->organization->relationLoaded('owner') && $this->organization->owner
                    ? ['id' => $this->organization->owner->id, 'name' => $this->organization->owner->name, 'email' => $this->organization->owner->email]
                    : null,
            ]),
            'plan' => new PlanResource($plan),
            'status' => $this->status->value,
            'current_period_start' => $this->current_period_start->toDateString(),
            'current_period_end' => $this->current_period_end->toDateString(),
            'grace_until' => $this->grace_until?->toDateString(),
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
            'usage' => app(Usage::class)->summary($this->organization_id, $plan->max_farms, $plan->max_users, $plan->max_storage_mb),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->sortByDesc('created_at')->values()->map(fn ($p) => [
                'id' => $p->id,
                'amount' => ['amount' => $p->amount, 'currency' => $p->currency],
                'provider' => $p->provider,
                'provider_ref' => $p->provider_ref,
                'status' => $p->status,
                'period_start' => $p->period_start?->toDateString(),
                'period_end' => $p->period_end?->toDateString(),
                'paid_at' => $p->paid_at?->toIso8601ZuluString(),
                'notes' => $p->notes,
            ])),
            'history' => $this->whenLoaded('history', fn () => $this->history->sortByDesc('created_at')->values()->map(fn ($h) => [
                'event' => $h->event,
                'from_status' => $h->from_status,
                'to_status' => $h->to_status,
                'details' => $h->details,
                'changed_by' => $h->changed_by,
                'at' => $h->created_at->toIso8601ZuluString(),
            ])),
            'version' => $this->version,
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
