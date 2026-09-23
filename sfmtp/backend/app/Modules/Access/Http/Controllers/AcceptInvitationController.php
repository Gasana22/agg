<?php

namespace App\Modules\Access\Http\Controllers;

use App\Modules\Access\Application\Memberships;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Public side of an invitation: the emailed link opens the web page, which
 * reads the invitation and then accepts it, signed in or with a new account.
 */
class AcceptInvitationController
{
    public function __construct(private readonly Memberships $memberships) {}

    public function show(string $token): JsonResponse
    {
        $invitation = $this->memberships->findByToken($token);

        return new JsonResponse(['data' => [
            'type' => 'invitation_preview',
            'status' => $invitation->status(),
            'email' => $invitation->email,
            'farm' => ['id' => $invitation->farm->id, 'name' => $invitation->farm->name],
            'invited_by' => $invitation->inviter?->name,
            'roles' => $invitation->roles->pluck('name')->values(),
            'message' => $invitation->message,
            'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
            'account_exists' => User::whereRaw('LOWER(email) = ?', [$invitation->email])->exists(),
        ]]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'password' => ['sometimes', 'string', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);

        /** @var User|null $user */
        $user = Auth::guard('api')->user();
        [$membership, $created] = $this->memberships->accept($token, $user, $data);

        return new JsonResponse(['data' => [
            'type' => 'invitation_acceptance',
            'farm_id' => $membership->farm_id,
            'membership_id' => $membership->id,
            'account_created' => $created,
        ]], $created ? 201 : 200);
    }
}
