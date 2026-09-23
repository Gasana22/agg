<?php

namespace App\Modules\Access\Http\Controllers;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Access\Application\Memberships;
use App\Modules\Access\Domain\Models\FarmInvitation;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Farm-side invitation management. Accepting is in AcceptInvitationController. */
class InvitationController
{
    public function __construct(
        private readonly Memberships $memberships,
        private readonly FarmPermissions $permissions,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', Rule::in(FarmInvitation::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $invitations = FarmInvitation::with(['roles', 'inviter'])
            ->withStatus($data['filter']['status'] ?? 'pending')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 25));

        return JsonResource::collection($invitations->through(fn (FarmInvitation $i) => self::present($i)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertMayInvite();
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role_ids' => ['required', 'array', 'min:1', 'max:10'],
            'role_ids.*' => ['uuid'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        [$invitation] = $this->memberships->invite($request->user(), $data['email'], $data['role_ids'], $data['message'] ?? null);

        return new JsonResponse(['data' => self::present($invitation->load('inviter'))], 201);
    }

    public function resend(Request $request, string $farm, FarmInvitation $invitation): JsonResponse
    {
        $this->assertMayInvite();
        [$invitation] = $this->memberships->resend($invitation, $request->user());

        return new JsonResponse(['data' => self::present($invitation->load(['roles', 'inviter']))]);
    }

    public function destroy(Request $request, string $farm, FarmInvitation $invitation): Response
    {
        $this->assertMayInvite();
        $this->memberships->revoke($invitation, $request->user());

        return response()->noContent();
    }

    private function assertMayInvite(): void
    {
        if (! $this->permissions->allows('members.manage') && ! $this->permissions->allows('members.invite_workers')) {
            throw new ApiException(403, 'forbidden', 'You do not have permission to do this in this farm.', [
                'required_permission' => 'members.manage',
            ]);
        }
    }

    public static function present(FarmInvitation $i): array
    {
        return [
            'id' => $i->id,
            'type' => 'farm_invitation',
            'email' => $i->email,
            'status' => $i->status(),
            'roles' => $i->roles->map(fn ($r) => ['id' => $r->id, 'key' => $r->key, 'name' => $r->name])->values(),
            'message' => $i->message,
            'invited_by' => $i->inviter ? ['id' => $i->inviter->id, 'name' => $i->inviter->name] : null,
            'send_count' => $i->send_count,
            'expires_at' => $i->expires_at->toIso8601ZuluString(),
            'last_sent_at' => $i->last_sent_at->toIso8601ZuluString(),
            'accepted_at' => $i->accepted_at?->toIso8601ZuluString(),
            'revoked_at' => $i->revoked_at?->toIso8601ZuluString(),
            'created_at' => $i->created_at->toIso8601ZuluString(),
        ];
    }
}
