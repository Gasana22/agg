<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Events\LoginFailed;
use App\Modules\Identity\Domain\Events\UserLoggedIn;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserDevice;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\Hash;

/**
 * Password and MFA sign-in (docs/01-system-architecture.md §6).
 */
class AuthService
{
    // Hash of a random string: keeps unknown-email logins as slow as real ones.
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$N2VzUTU0OTQ1cTNvOHlSVg$i8NAuf38wfhTr6lH0BHhWBj4NsxxzwmqwvgLC7FKG4Q';

    public function __construct(
        private readonly TokenService $tokens,
        private readonly MfaService $mfa,
    ) {}

    /**
     * @param  array{name?:string, platform?:string, push_token?:string}  $device
     * @return array{mfa_required:bool, tokens?:IssuedTokens, mfa_token?:string}
     */
    public function login(string $email, string $password, string $client, array $device, ?string $ip, ?string $userAgent): array
    {
        $user = User::where('email', mb_strtolower(trim($email)))->first();

        if ($user === null) {
            Hash::check($password, self::DUMMY_HASH);
            LoginFailed::dispatch($email, null, 'unknown_user');
            throw $this->invalidCredentials();
        }

        // Locked or disabled accounts get the same generic answer (no enumeration).
        if ($user->isLocked() || ! $user->isActive()) {
            Hash::check($password, self::DUMMY_HASH);
            LoginFailed::dispatch($email, $user, $user->isLocked() ? 'locked' : 'disabled');
            throw $this->invalidCredentials();
        }

        if (! Hash::check($password, $user->password)) {
            $this->recordFailure($user);
            LoginFailed::dispatch($email, $user, 'bad_password');
            throw $this->invalidCredentials();
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        if ($user->hasMfa()) {
            return [
                'mfa_required' => true,
                'mfa_token' => $this->tokens->issueMfaToken($user, $client, $device),
            ];
        }

        return ['mfa_required' => false, 'tokens' => $this->completeLogin($user, $client, $device, $ip, $userAgent)];
    }

    /**
     * Second step: an authenticator code or a recovery code.
     */
    public function completeMfa(string $mfaToken, ?string $code, ?string $recoveryCode, ?string $ip, ?string $userAgent): IssuedTokens
    {
        $claims = $this->tokens->decodeMfaToken($mfaToken);
        if ($claims === null) {
            throw ApiException::unauthenticated('mfa_token_invalid', 'The sign-in attempt has expired. Please sign in again.');
        }

        $user = User::find($claims->sub);
        if ($user === null || ! $user->isActive() || ! $user->hasMfa()) {
            throw ApiException::unauthenticated('mfa_token_invalid', 'The sign-in attempt has expired. Please sign in again.');
        }

        $ok = $code !== null
            ? $this->mfa->verify($user, $code)
            : ($recoveryCode !== null && $this->mfa->useRecoveryCode($user, $recoveryCode));

        if (! $ok) {
            $this->recordFailure($user);
            LoginFailed::dispatch($user->email, $user, 'bad_mfa_code');
            throw ApiException::unprocessable('invalid_mfa_code', 'The authentication code is invalid.', ['code' => ['The authentication code is invalid.']]);
        }

        return $this->completeLogin($user, $claims->cli, (array) ($claims->dev ?? []), $ip, $userAgent);
    }

    private function completeLogin(User $user, string $client, array $device, ?string $ip, ?string $userAgent): IssuedTokens
    {
        $user->forceFill(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();

        $deviceModel = UserDevice::create([
            'user_id' => $user->id,
            'client' => $client,
            'name' => $device['name'] ?? null,
            'platform' => $device['platform'] ?? ($client === 'web' ? 'web' : null),
            'push_token' => $device['push_token'] ?? null,
            'last_ip' => $ip,
            'last_seen_at' => now(),
        ]);

        UserLoggedIn::dispatch($user, $client);

        return $this->tokens->issue($user, $client, $deviceModel, $ip, $userAgent);
    }

    private function recordFailure(User $user): void
    {
        $failures = $user->failed_logins + 1;
        $attrs = ['failed_logins' => $failures];

        if ($failures >= config('sfmtp.security.max_failed_logins')) {
            // Exponential back-off: 15 min, 30 min, 60 min … capped at 24 h.
            $steps = $failures - config('sfmtp.security.max_failed_logins');
            $seconds = min(config('sfmtp.security.lockout_seconds') * (2 ** $steps), 86400);
            $attrs['locked_until'] = now()->addSeconds($seconds);
        }

        $user->forceFill($attrs)->save();
    }

    private function invalidCredentials(): ApiException
    {
        return ApiException::unauthenticated('invalid_credentials', 'The email or password is incorrect.');
    }
}
