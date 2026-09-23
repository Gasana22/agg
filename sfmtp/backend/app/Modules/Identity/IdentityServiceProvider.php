<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Application\PlatformAdminMfaRequirement;
use App\Modules\Identity\Application\TokenService;
use App\Modules\Identity\Contracts\MfaRequirement;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TokenService::class);
        // Tenancy replaces this with a farm-role-aware implementation.
        $this->app->bindIf(MfaRequirement::class, PlatformAdminMfaRequirement::class);
    }

    public function boot(): void
    {
        // Stateless JWT guard: `auth:api`.
        Auth::viaRequest('jwt', function (Request $request) {
            $jwt = $request->bearerToken();
            if (! $jwt) {
                return null;
            }

            $tokens = $this->app->make(TokenService::class);
            $claims = $tokens->decodeAccess($jwt);
            if ($claims === null || ! $tokens->sessionIsActive($claims->sid)) {
                return null;
            }

            $user = User::find($claims->sub);
            if ($user === null || ! $user->isActive()) {
                return null;
            }

            $request->attributes->set('session_id', $claims->sid);
            $request->attributes->set('token_client', $claims->cli);
            $request->attributes->set('device_id', $claims->did ?? null);

            return $user;
        });

        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by('auth-email:'.mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('auth-ip:'.$request->ip()),
        ]);
        RateLimiter::for('refresh', fn (Request $request) => Limit::perMinute(30)->by('refresh:'.$request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by('public:'.$request->ip()));

        ResetPassword::createUrlUsing(fn (User $user, string $token) => config('sfmtp.web_url').'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $user->email,
        ]));
    }
}
