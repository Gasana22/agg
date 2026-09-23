<?php

namespace App\Modules\Support\Http\Controllers;

use App\Modules\Support\Application\SupportAccess;
use App\Modules\Support\Application\Tickets;
use App\Modules\Support\Domain\Models\SupportAccessGrant;
use App\Modules\Support\Domain\Models\SupportTicket;
use App\Modules\Support\Http\Resources\TicketResource;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Farm-side support: members open tickets, owners see all their organization's. */
class MemberTicketController
{
    public function __construct(
        private readonly Tickets $tickets,
        private readonly SupportAccess $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return TicketResource::collection(
            $this->tickets->visibleTo($request->user())->with(['farm', 'opener'])
                ->orderByDesc('last_activity_at')->orderBy('id')
                ->cursorPaginate((int) $request->query('per_page', 25))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'farm_id' => ['nullable', 'uuid'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]);

        return (new TicketResource($this->tickets->open($request->user(), $data)->load(['farm', 'opener', 'messages.author'])))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, string $ticket): TicketResource
    {
        return new TicketResource($this->find($request, $ticket)->load(['farm', 'opener', 'assignee', 'messages.author', 'grants']));
    }

    public function reply(Request $request, string $ticket): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $model = $this->find($request, $ticket);
        $this->tickets->reply($model, $request->user(), $data['body'], false, false);

        return (new TicketResource($model->refresh()->load(['farm', 'opener', 'assignee', 'messages.author', 'grants'])))->response()->setStatusCode(201);
    }

    public function grantAccess(Request $request, string $ticket): JsonResponse
    {
        $data = $request->validate(['hours' => ['required', 'integer', 'min:1', 'max:'.SupportAccess::MAX_HOURS]]);
        $grant = $this->access->grant($this->find($request, $ticket), $request->user(), $data['hours']);

        return new JsonResponse(['data' => [
            'id' => $grant->id,
            'type' => 'support_access_grant',
            'farm_id' => $grant->farm_id,
            'expires_at' => $grant->expires_at->toIso8601ZuluString(),
            'active' => true,
        ]], 201);
    }

    public function revokeAccess(Request $request, string $ticket, string $grant): Response
    {
        $model = $this->find($request, $ticket);
        $grantModel = SupportAccessGrant::where('ticket_id', $model->id)->whereKey($grant)->first() ?? throw ApiException::notFound();
        $this->access->revoke($grantModel, $request->user());

        return response()->noContent();
    }

    private function find(Request $request, string $id): SupportTicket
    {
        return $this->tickets->visibleTo($request->user())->whereKey($id)->first() ?? throw ApiException::notFound();
    }
}
