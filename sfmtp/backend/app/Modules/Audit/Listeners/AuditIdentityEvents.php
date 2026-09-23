<?php

namespace App\Modules\Audit\Listeners;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Events;
use Illuminate\Events\Dispatcher;

/**
 * Identity sits below Audit in the module graph, so it publishes events and
 * this subscriber records them (docs/09-module-dependency-map.md).
 */
class AuditIdentityEvents
{
    private function audit(): AuditLogger
    {
        // Resolved per event: the logger captures the current request.
        return app(AuditLogger::class);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Events\UserLoggedIn::class => fn (Events\UserLoggedIn $e) => $this->audit()->record('auth.login', $e->user, null, ['client' => $e->client], ['user_id' => $e->user->id, 'farm_id' => null]),
            Events\LoginFailed::class => fn (Events\LoginFailed $e) => $this->audit()->record('auth.login_failed', $e->user, null, ['email' => $e->email, 'reason' => $e->reason], ['user_id' => $e->user?->id, 'farm_id' => null]),
            Events\RefreshTokenReuseDetected::class => fn (Events\RefreshTokenReuseDetected $e) => $this->audit()->record('auth.refresh_token_reuse', $e->user, null, ['session_id' => $e->familyId], ['user_id' => $e->user->id, 'farm_id' => null]),
            Events\LoggedOut::class => fn (Events\LoggedOut $e) => $this->audit()->record('auth.logout', $e->user, null, ['session_id' => $e->familyId], ['farm_id' => null]),
            Events\MfaEnabled::class => fn (Events\MfaEnabled $e) => $this->audit()->record('auth.mfa_enabled', $e->user, meta: ['farm_id' => null]),
            Events\MfaDisabled::class => fn (Events\MfaDisabled $e) => $this->audit()->record('auth.mfa_disabled', $e->user, meta: ['farm_id' => null]),
            Events\RecoveryCodeUsed::class => fn (Events\RecoveryCodeUsed $e) => $this->audit()->record('auth.recovery_code_used', $e->user, meta: ['user_id' => $e->user->id, 'farm_id' => null]),
            Events\PasswordWasReset::class => fn (Events\PasswordWasReset $e) => $this->audit()->record('auth.password_reset', $e->user, meta: ['user_id' => $e->user->id, 'farm_id' => null]),
            Events\DeviceRevoked::class => fn (Events\DeviceRevoked $e) => $this->audit()->record('auth.device_revoked', ['type' => 'user_device', 'id' => $e->deviceId], meta: ['farm_id' => null]),
        ];
    }
}
