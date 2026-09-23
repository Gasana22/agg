<?php

namespace App\Support\Http\Middleware;

use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key support for POST requests (docs/06-api-contracts.md §1).
 *
 * - A repeat of the same key + same request replays the stored response.
 * - The same key with a different request body is rejected (422).
 * - Mobile clients (token claim cli=mobile) must send a key.
 * - 5xx responses are not stored, so the client can retry.
 */
class EnforceIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->method() !== 'POST') {
            return $next($request);
        }

        $key = $request->headers->get('Idempotency-Key');

        if ($key === null) {
            if ($request->attributes->get('token_client') === 'mobile') {
                throw ApiException::unprocessable('idempotency_key_required', 'Mobile requests must include an Idempotency-Key header.');
            }

            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9\-_]{8,128}$/', $key)) {
            throw ApiException::unprocessable('idempotency_key_invalid', 'Idempotency-Key must be 8–128 characters of [A-Za-z0-9-_].');
        }

        $userId = $request->user()?->getAuthIdentifier() ?? 'guest:'.$request->ip();
        $cacheKey = "idem:{$userId}:{$key}";
        $fingerprint = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        $stored = Cache::get($cacheKey);
        if ($stored !== null) {
            return $this->replay($stored, $fingerprint);
        }

        $lock = Cache::lock($cacheKey.':lock', 30);
        if (! $lock->get()) {
            throw ApiException::conflict('idempotency_in_progress', 'A request with this Idempotency-Key is still being processed.');
        }

        try {
            // Re-check after acquiring the lock.
            if (($stored = Cache::get($cacheKey)) !== null) {
                return $this->replay($stored, $fingerprint);
            }

            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(),
                    'headers' => array_filter([
                        'Content-Type' => $response->headers->get('Content-Type'),
                        'Location' => $response->headers->get('Location'),
                    ]),
                ], config('sfmtp.idempotency.ttl'));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function replay(array $stored, string $fingerprint): Response
    {
        if (! hash_equals($stored['fingerprint'], $fingerprint)) {
            throw ApiException::unprocessable('idempotency_key_reused', 'This Idempotency-Key was already used for a different request.');
        }

        $response = new JsonResponse(null, $stored['status'], $stored['headers']);
        $response->setContent($stored['body']);
        $response->headers->set('Idempotent-Replayed', 'true');

        return $response;
    }
}
