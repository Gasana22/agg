<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Application\TokenService;
use App\Modules\Identity\Domain\Events\LoggedOut;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\MfaChallengeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TokenService $tokens,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
            $request->validated('client', 'web'),
            $request->validated('device', []),
            $request->ip(),
            $request->userAgent(),
        );

        if ($result['mfa_required']) {
            return new JsonResponse(['data' => [
                'mfa_required' => true,
                'mfa_token' => $result['mfa_token'],
                'methods' => ['totp', 'recovery_code'],
            ]]);
        }

        return new JsonResponse(['data' => ['mfa_required' => false] + $result['tokens']->toArray()]);
    }

    public function mfaChallenge(MfaChallengeRequest $request): JsonResponse
    {
        $tokens = $this->auth->completeMfa(
            $request->validated('mfa_token'),
            $request->validated('code'),
            $request->validated('recovery_code'),
            $request->ip(),
            $request->userAgent(),
        );

        return new JsonResponse(['data' => ['mfa_required' => false] + $tokens->toArray()]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'max:255']]);

        $tokens = $this->tokens->rotate($data['refresh_token'], $request->ip(), $request->userAgent());

        return new JsonResponse(['data' => $tokens->toArray()]);
    }

    public function logout(Request $request): Response
    {
        $sessionId = $request->attributes->get('session_id');
        $this->tokens->revokeFamily($sessionId);
        LoggedOut::dispatch($request->user(), $sessionId);

        return response()->noContent();
    }
}
