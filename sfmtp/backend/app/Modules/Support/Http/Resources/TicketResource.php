<?php

namespace App\Modules\Support\Http\Resources;

use App\Modules\Support\Domain\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupportTicket
 *
 * `staff` controls whether internal notes are included.
 */
class TicketResource extends JsonResource
{
    public bool $staff = false;

    public function asStaff(): static
    {
        $this->staff = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $staff = $this->staff;

        return [
            'id' => $this->id,
            'type' => 'support_ticket',
            'reference' => $this->reference,
            'subject' => $this->subject,
            'status' => $this->status,
            'priority' => $this->priority,
            'organization' => $this->whenLoaded('organization', fn () => ['id' => $this->organization->id, 'name' => $this->organization->name]),
            'farm' => $this->whenLoaded('farm', fn () => $this->farm ? ['id' => $this->farm->id, 'name' => $this->farm->name, 'code' => $this->farm->code] : null),
            'opened_by' => $this->whenLoaded('opener', fn () => ['id' => $this->opener->id, 'name' => $this->opener->name]),
            'assigned_to' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages
                ->filter(fn ($m) => $staff || ! $m->is_internal)
                ->sortBy('created_at')
                ->values()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'author' => ['id' => $m->author->id, 'name' => $m->author->name, 'is_staff' => $m->author->isPlatformAdmin()],
                    'body' => $m->body,
                    'is_internal' => $m->is_internal,
                    'created_at' => $m->created_at->toIso8601ZuluString(),
                ])),
            'access_grants' => $this->whenLoaded('grants', fn () => $this->grants->sortByDesc('created_at')->values()->map(fn ($g) => [
                'id' => $g->id,
                'expires_at' => $g->expires_at->toIso8601ZuluString(),
                'revoked_at' => $g->revoked_at?->toIso8601ZuluString(),
                'active' => $g->isActive(),
            ])),
            'last_activity_at' => $this->last_activity_at->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
