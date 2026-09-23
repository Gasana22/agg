<?php

namespace App\Modules\Support\Http\Controllers;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Platform\Application\PlatformPermissions;
use App\Modules\Support\Application\SupportAccess;
use App\Modules\Support\Application\Tickets;
use App\Modules\Support\Domain\Models\SupportTicket;
use App\Modules\Support\Http\Resources\TicketResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminTicketController
{
    public function __construct(
        private readonly Tickets $tickets,
        private readonly SupportAccess $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', Rule::in(['open', 'pending', 'resolved', 'closed'])],
            'filter.assigned_to' => ['sometimes', 'string', 'max:40'],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $data['filter'] ?? [];

        $page = SupportTicket::with(['organization', 'farm', 'opener', 'assignee'])
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(($filter['assigned_to'] ?? null) === 'me', fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when(($filter['assigned_to'] ?? null) === 'none', fn ($q) => $q->whereNull('assigned_to'))
            ->when($data['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(subject) LIKE ?', ['%'.mb_strtolower($v).'%'])
                ->orWhere('reference', strtoupper($v))))
            ->orderByDesc('last_activity_at')->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 25));

        return TicketResource::collection($page->through(fn ($t) => (new TicketResource($t))->asStaff()));
    }

    public function show(string $ticket): TicketResource
    {
        return $this->present(SupportTicket::findOrFail($ticket));
    }

    private function present(SupportTicket $ticket): TicketResource
    {
        return (new TicketResource($ticket->load(['organization', 'farm', 'opener', 'assignee', 'messages.author', 'grants'])))->asStaff();
    }

    public function reply(Request $request, string $ticket): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($ticket);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'is_internal' => ['sometimes', 'boolean'],
        ]);
        $this->tickets->reply($ticket, $request->user(), $data['body'], (bool) ($data['is_internal'] ?? false), true);

        return $this->present($ticket->refresh())->response()->setStatusCode(201);
    }

    public function update(Request $request, string $ticket, PlatformPermissions $platform): TicketResource
    {
        $ticket = SupportTicket::findOrFail($ticket);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'pending', 'resolved', 'closed'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assigned_to' => ['sometimes', 'nullable', 'uuid', function ($attr, $value, $fail) use ($platform) {
                $user = $value ? User::find($value) : null;
                if ($value && (! $user || ! $platform->allows($user, 'support.manage'))) {
                    $fail('Tickets can only be assigned to support staff.');
                }
            }],
        ]);

        $ticket = $this->tickets->update($ticket, $data, $request->user());
        if (in_array($ticket->status, ['resolved', 'closed'], true)) {
            $this->access->revokeForTicket($ticket, $request->user());
        }

        return $this->present($ticket);
    }
}
