<?php

namespace App\Modules\Parties\Http\Controllers;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Parties\Application\PortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password as PasswordRule;

/** Public side of a portal invitation: read it, then accept it signed in or with a new account. */
class AcceptPortalInvitationController
{
    public function __construct(private readonly PortalAccess $access) {}

    public function show(string $token): JsonResponse
    {
        return new JsonResponse(['data' => $this->access->preview($token)]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'password' => ['sometimes', 'string', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
            'party_id' => ['sometimes', 'uuid'],
        ]);
        /** @var User|null $user */
        $user = Auth::guard('api')->user();
        [$link, $created] = $this->access->accept($token, $user, $data);

        return new JsonResponse(['data' => [
            'type' => 'portal_invitation_acceptance',
            'kind' => $link->kind,
            'party' => ['id' => $link->party->id, 'name' => $link->party->name],
            'farm' => ['id' => $link->farm->id, 'name' => $link->farm->name],
            'account_created' => $created,
        ]], $created ? 201 : 200);
    }
}
