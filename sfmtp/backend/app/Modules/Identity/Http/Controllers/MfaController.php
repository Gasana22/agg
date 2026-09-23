<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\MfaService;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MfaController
{
    public function __construct(private readonly MfaService $mfa) {}

    public function setup(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->mfa->beginSetup($request->user())]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        return new JsonResponse(['data' => [
            'mfa_enabled' => true,
            'recovery_codes' => $this->mfa->confirmSetup($request->user(), $data['code']),
        ]]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $user = $request->user();

        if (! $this->mfa->verify($user, $data['code'])) {
            throw ApiException::unprocessable('invalid_mfa_code', 'The authentication code is invalid.', ['code' => ['The authentication code is invalid.']]);
        }

        return new JsonResponse(['data' => ['recovery_codes' => $this->mfa->regenerateRecoveryCodes($user)]]);
    }

    public function disable(Request $request): Response
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $this->mfa->disable($request->user(), $data['code']);

        return response()->noContent();
    }
}
