<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\TokenService;
use App\Modules\Identity\Domain\Events\PasswordWasReset;
use App\Modules\Identity\Domain\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordController
{
    public function __construct(private readonly TokenService $tokens) {}

    /** Always 202, whether or not the email exists (no account enumeration). */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        Password::sendResetLink(['email' => mb_strtolower(trim($data['email']))]);

        return new JsonResponse(['data' => ['status' => 'sent_if_account_exists']], 202);
    }

    public function reset(Request $request): Response
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);

        $status = Password::reset(
            ['email' => mb_strtolower(trim($data['email']))] + $data,
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'failed_logins' => 0, 'locked_until' => null])->save();
                $this->tokens->revokeAllForUser($user->id);
                PasswordWasReset::dispatch($user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ApiException::unprocessable('invalid_reset_token', 'This password reset link is invalid or has expired.');
        }

        return response()->noContent();
    }
}
