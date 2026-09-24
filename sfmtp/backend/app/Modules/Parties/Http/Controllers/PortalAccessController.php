<?php

namespace App\Modules\Parties\Http\Controllers;

use App\Modules\Parties\Application\PortalAccess;
use App\Modules\Parties\Domain\Models\PartyLink;
use App\Modules\Parties\Domain\Models\PortalInvitation;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Farm side: who has portal access, invitations, unlinking. */
class PortalAccessController
{
    public function __construct(private readonly PortalAccess $access) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => $this->access->overview()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:supplier,customer'],
            'record_id' => ['required', 'uuid'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        [$invitation] = $this->access->invite($data['kind'], $data['record_id'], $data['email'], $data['message'] ?? null);

        return new JsonResponse(['data' => [
            'type' => 'portal_invitation',
            'id' => $invitation->id,
            'kind' => $invitation->kind,
            'record_id' => $invitation->record_id,
            'email' => $invitation->email,
            'status' => $invitation->status(),
            'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
        ]], 201);
    }

    public function revokeInvitation(string $farm, string $portalInvitation): JsonResponse
    {
        $model = PortalInvitation::find($portalInvitation) ?? throw ApiException::notFound();
        $this->access->revokeInvitation($model);

        return new JsonResponse(null, 204);
    }

    public function unlink(string $farm, string $partyLink): JsonResponse
    {
        $model = PartyLink::where('farm_id', $farm)->find($partyLink) ?? throw ApiException::notFound();
        $this->access->unlink($model);

        return new JsonResponse(null, 204);
    }
}
