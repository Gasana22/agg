<?php

namespace App\Modules\Integrations\Application;

use App\Modules\Integrations\Contracts\ProviderDirectory;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs a call against the providers of a kind in order (the default first)
 * and fails over to the next on a retryable failure (ADR-0018).
 *
 * A provider that fails three times in a row is skipped for five minutes (a
 * circuit breaker), unless it is the only one left. Every outcome is
 * reported to the directory, so the admin portal shows each provider's
 * health.
 */
class Router
{
    public const TRIP_AFTER = 3;

    public const OPEN_SECONDS = 300;

    public function __construct(private readonly ProviderDirectory $directory) {}

    /**
     * @template T
     *
     * @param  Closure(ProviderConfig): T  $call
     * @return array{0: T, 1: ProviderConfig} the result and the provider that gave it
     */
    public function run(string $kind, Closure $call, ?string $onlyId = null): array
    {
        $candidates = $onlyId !== null
            ? array_filter([$this->directory->find($onlyId)])
            : $this->directory->candidates($kind);
        if ($candidates === []) {
            throw new IntegrationUnavailable($kind);
        }

        // Providers with an open circuit go last rather than being dropped.
        usort($candidates, fn (ProviderConfig $a, ProviderConfig $b) => $this->isOpen($a) <=> $this->isOpen($b));

        $errors = [];
        foreach ($candidates as $provider) {
            try {
                $result = $call($provider);
                $this->succeeded($provider);

                return [$result, $provider];
            } catch (ProviderFailure $e) {
                if (! $e->retryable) {
                    throw $e;
                }
                $errors[$provider->name] = $e->getMessage();
                $this->failed($provider, $e->getMessage());
            } catch (ConnectionException|RequestException $e) {
                $message = $e instanceof RequestException ? "HTTP {$e->response->status()}" : 'no connection';
                $errors[$provider->name] = $message;
                $this->failed($provider, $message);
            }
        }

        throw new IntegrationUnavailable($kind, $errors);
    }

    public function isOpen(ProviderConfig $provider): bool
    {
        return Cache::has("integrations:open:{$provider->id}");
    }

    private function succeeded(ProviderConfig $provider): void
    {
        Cache::forget("integrations:failures:{$provider->id}");
        Cache::forget("integrations:open:{$provider->id}");
        $this->directory->report($provider->id, true);
    }

    private function failed(ProviderConfig $provider, string $error): void
    {
        $failures = (int) Cache::get("integrations:failures:{$provider->id}", 0) + 1;
        Cache::put("integrations:failures:{$provider->id}", $failures, now()->addHour());
        if ($failures >= self::TRIP_AFTER) {
            Cache::put("integrations:open:{$provider->id}", true, now()->addSeconds(self::OPEN_SECONDS));
        }
        Log::warning('Integration call failed', ['kind' => $provider->kind, 'provider' => $provider->provider, 'error' => $error]);
        $this->directory->report($provider->id, false, $error);
    }
}
