<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Contracts\MfaRequirement;
use App\Modules\Identity\Domain\Events\MfaDisabled;
use App\Modules\Identity\Domain\Events\MfaEnabled;
use App\Modules\Identity\Domain\Events\RecoveryCodeUsed;
use App\Modules\Identity\Domain\Models\MfaRecoveryCode;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserMfaFactor;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238) authenticator-app MFA with one-time recovery codes.
 */
class MfaService
{
    public function __construct(
        private readonly Google2FA $totp,
        private readonly MfaRequirement $requirement,
    ) {}

    /**
     * Start (or restart) TOTP enrolment. Returns the secret and otpauth:// URI
     * for the authenticator app; nothing is enforced until confirm().
     *
     * @return array{secret:string, otpauth_uri:string}
     */
    public function beginSetup(User $user): array
    {
        if ($user->hasMfa()) {
            throw ApiException::conflict('mfa_already_enabled', 'MFA is already enabled for this account.');
        }

        $secret = $this->totp->generateSecretKey(32);

        DB::transaction(function () use ($user, $secret) {
            $user->mfaFactors()->whereNull('confirmed_at')->delete();
            $user->mfaFactors()->create(['type' => 'totp', 'secret' => $secret]);
        });

        return [
            'secret' => $secret,
            'otpauth_uri' => $this->totp->getQRCodeUrl('SFMTP', $user->email, $secret),
        ];
    }

    /**
     * Confirm enrolment with a first valid code. Returns the recovery codes,
     * which are shown to the user exactly once.
     *
     * @return array<int,string>
     */
    public function confirmSetup(User $user, string $code): array
    {
        /** @var UserMfaFactor|null $factor */
        $factor = $user->mfaFactors()->where('type', 'totp')->whereNull('confirmed_at')->latest()->first();
        if ($factor === null) {
            throw ApiException::unprocessable('mfa_setup_not_started', 'Start MFA setup first.');
        }

        if (! $this->checkTotp($factor, $code)) {
            throw ApiException::unprocessable('invalid_mfa_code', 'The authentication code is invalid.', ['code' => ['The authentication code is invalid.']]);
        }

        $codes = DB::transaction(function () use ($user, $factor) {
            $factor->forceFill(['confirmed_at' => now()])->save();
            $user->forceFill(['mfa_enabled_at' => now()])->save();

            return $this->regenerateRecoveryCodes($user);
        });

        MfaEnabled::dispatch($user);

        return $codes;
    }

    public function disable(User $user, string $code): void
    {
        if ($this->requirement->requiresMfa($user)) {
            throw ApiException::forbidden('mfa_required_by_policy', 'MFA is required for your role and cannot be disabled.');
        }

        if (! $this->verify($user, $code)) {
            throw ApiException::unprocessable('invalid_mfa_code', 'The authentication code is invalid.', ['code' => ['The authentication code is invalid.']]);
        }

        DB::transaction(function () use ($user) {
            $user->mfaFactors()->delete();
            MfaRecoveryCode::where('user_id', $user->id)->delete();
            $user->forceFill(['mfa_enabled_at' => null])->save();
        });

        MfaDisabled::dispatch($user);
    }

    /** Verify a TOTP code for a user with confirmed MFA. */
    public function verify(User $user, string $code): bool
    {
        $factor = $user->confirmedTotp();

        return $factor !== null && $this->checkTotp($factor, $code);
    }

    /** Consume a recovery code. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $used = MfaRecoveryCode::where('user_id', $user->id)
            ->where('code_hash', MfaRecoveryCode::hashCode($code))
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        if ($used === 1) {
            RecoveryCodeUsed::dispatch($user);

            return true;
        }

        return false;
    }

    /** @return array<int,string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        MfaRecoveryCode::where('user_id', $user->id)->delete();

        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];
        for ($i = 0; $i < config('sfmtp.security.recovery_codes'); $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
            MfaRecoveryCode::create(['user_id' => $user->id, 'code_hash' => MfaRecoveryCode::hashCode($raw)]);
        }

        return $codes;
    }

    /**
     * Accepts codes from the current ±1 time step, and never the same step twice.
     */
    private function checkTotp(UserMfaFactor $factor, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $timestep = $this->totp->verifyKeyNewer($factor->secret, $code, $factor->last_used_timestep, 1);
        if ($timestep === false) {
            return false;
        }

        $factor->forceFill(['last_used_timestep' => $timestep])->save();

        return true;
    }
}
