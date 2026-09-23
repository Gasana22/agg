<?php

namespace App\Modules\Identity\Application;

final class IssuedTokens
{
    public function __construct(
        public readonly string $accessToken,
        public readonly int $expiresIn,
        public readonly string $refreshToken,
        public readonly int $refreshExpiresIn,
        public readonly string $sessionId,
    ) {}

    public function toArray(): array
    {
        return [
            'token_type' => 'Bearer',
            'access_token' => $this->accessToken,
            'expires_in' => $this->expiresIn,
            'refresh_token' => $this->refreshToken,
            'refresh_expires_in' => $this->refreshExpiresIn,
        ];
    }
}
