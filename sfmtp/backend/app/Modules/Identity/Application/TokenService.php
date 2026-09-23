<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Events\RefreshTokenReuseDetected;
use App\Modules\Identity\Domain\Models\RefreshToken;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserDevice;
use App\Support\Http\ApiException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Access tokens: short-lived HS256 JWTs carrying identity only (no roles or
 * farm IDs, which are checked on every request).
 * Refresh tokens: opaque, stored hashed, rotated on every use. Presenting an
 * already-rotated token revokes the whole family (theft detection).
 */
class TokenService
{
    private const ALGO = 'HS256';

    public function issue(User $user, string $client, ?UserDevice $device, ?string $ip = null, ?string $userAgent = null): IssuedTokens
    {
        return $this->issueInFamily($user, $client, $device?->id, (string) Str::uuid7(), $ip, $userAgent);
    }

    /**
     * Rotate a refresh token. Throws 401 on unknown, expired or reused tokens.
     */
    public function rotate(string $plainRefreshToken, ?string $ip = null, ?string $userAgent = null): IssuedTokens
    {
        // Revocations must survive the failure response, so they happen after
        // (outside) the rotation transaction, which would otherwise roll them back.
        $outcome = DB::transaction(function () use ($plainRefreshToken, $ip, $userAgent) {
            /** @var RefreshToken|null $token */
            $token = RefreshToken::where('token_hash', $this->hash($plainRefreshToken))->lockForUpdate()->first();

            if ($token === null) {
                return ['error' => 'invalid_refresh_token'];
            }

            if ($token->revoked_at !== null) {
                if ($token->replaced_by === null) {
                    return ['error' => 'invalid_refresh_token'];
                }

                // Two requests refreshing at the same moment (browser tabs, a
                // retry) is normal: refuse softly for a short window.
                if ($token->revoked_at->diffInSeconds(now(), true) <= config('sfmtp.refresh.reuse_grace_seconds')) {
                    return ['error' => 'refresh_token_superseded'];
                }

                // A rotated token presented again later: assume it was stolen.
                return ['error' => 'token_reuse_detected', 'revoke' => $token];
            }

            if ($token->expires_at->isPast()) {
                return ['error' => 'refresh_token_expired'];
            }

            if (! $token->user->isActive()) {
                return ['error' => 'account_disabled', 'revoke' => $token];
            }

            if ($token->device_id && UserDevice::whereKey($token->device_id)->whereNotNull('revoked_at')->exists()) {
                return ['error' => 'device_revoked', 'revoke' => $token];
            }

            $issued = $this->issueInFamily($token->user, $token->client, $token->device_id, $token->family_id, $ip, $userAgent, $newId);
            $token->forceFill(['revoked_at' => now(), 'replaced_by' => $newId])->save();

            return ['tokens' => $issued];
        });

        if (isset($outcome['tokens'])) {
            return $outcome['tokens'];
        }

        if (isset($outcome['revoke'])) {
            $this->revokeFamily($outcome['revoke']->family_id);
            if ($outcome['error'] === 'token_reuse_detected') {
                RefreshTokenReuseDetected::dispatch($outcome['revoke']->user, $outcome['revoke']->family_id);
            }
        }

        throw match ($outcome['error']) {
            'token_reuse_detected' => ApiException::unauthenticated('token_reuse_detected', 'This session has been revoked. Please sign in again.'),
            'refresh_token_superseded' => ApiException::unauthenticated('refresh_token_superseded', 'This refresh token was just rotated by a parallel request. Retry with the newer token.'),
            'refresh_token_expired' => ApiException::unauthenticated('refresh_token_expired', 'The session has expired. Please sign in again.'),
            'account_disabled' => ApiException::unauthenticated('account_disabled', 'This account is disabled.'),
            'device_revoked' => ApiException::unauthenticated('device_revoked', 'This device has been signed out.'),
            default => ApiException::unauthenticated('invalid_refresh_token', 'The refresh token is invalid.'),
        };
    }

    public function revokeFamily(string $familyId): void
    {
        RefreshToken::where('family_id', $familyId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function revokeAllForUser(string $userId): void
    {
        RefreshToken::where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function revokeDevice(string $deviceId): void
    {
        RefreshToken::where('device_id', $deviceId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** True while the login session (refresh family) has an unrevoked, unexpired token. */
    public function sessionIsActive(string $familyId): bool
    {
        return RefreshToken::where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Decode and validate an access token. Returns null for anything invalid.
     */
    public function decodeAccess(string $jwt): ?stdClass
    {
        return $this->decode($jwt, 'access');
    }

    /**
     * Short-lived token proving the password step succeeded while MFA is pending.
     */
    public function issueMfaToken(User $user, string $client, array $device): string
    {
        $now = time();

        return JWT::encode([
            'iss' => config('sfmtp.jwt.issuer'),
            'sub' => $user->id,
            'typ' => 'mfa',
            'cli' => $client,
            'dev' => $device,
            'iat' => $now,
            'exp' => $now + config('sfmtp.jwt.mfa_ttl'),
            'jti' => (string) Str::uuid7(),
        ], $this->secret(), self::ALGO);
    }

    public function decodeMfaToken(string $jwt): ?stdClass
    {
        return $this->decode($jwt, 'mfa');
    }

    private function issueInFamily(User $user, string $client, ?string $deviceId, string $familyId, ?string $ip, ?string $userAgent, ?string &$newId = null): IssuedTokens
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $ttl = $client === 'mobile' ? config('sfmtp.refresh.ttl_mobile') : config('sfmtp.refresh.ttl_web');

        $refresh = RefreshToken::create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'family_id' => $familyId,
            'token_hash' => $this->hash($plain),
            'client' => $client,
            'expires_at' => now()->addSeconds($ttl),
            'ip' => $ip,
            'user_agent' => $userAgent ? Str::limit($userAgent, 250, '') : null,
        ]);
        $newId = $refresh->id;

        $now = time();
        $accessTtl = config('sfmtp.jwt.access_ttl');
        $access = JWT::encode([
            'iss' => config('sfmtp.jwt.issuer'),
            'sub' => $user->id,
            'typ' => 'access',
            'cli' => $client,
            'sid' => $familyId,
            'did' => $deviceId,
            'iat' => $now,
            'exp' => $now + $accessTtl,
            'jti' => (string) Str::uuid7(),
        ], $this->secret(), self::ALGO);

        return new IssuedTokens($access, $accessTtl, $plain, $ttl, $familyId);
    }

    private function decode(string $jwt, string $type): ?stdClass
    {
        try {
            JWT::$leeway = config('sfmtp.jwt.leeway');
            $claims = JWT::decode($jwt, new Key($this->secret(), self::ALGO));
        } catch (Throwable) {
            return null;
        }

        if (($claims->typ ?? null) !== $type || ($claims->iss ?? null) !== config('sfmtp.jwt.issuer') || empty($claims->sub)) {
            return null;
        }

        return $claims;
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    private function secret(): string
    {
        $secret = (string) config('sfmtp.jwt.secret');
        if (strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET must be set to at least 32 characters.');
        }

        return $secret;
    }
}
