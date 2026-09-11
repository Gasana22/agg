<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Offline-mode support: a field worker's app queues an action (check-in, a
 * health log, ...) while offline and sends it once connectivity returns. If
 * the response is lost to a dropped connection, the app retries — without
 * this, the retry creates a second record. A client that sends an
 * Idempotency-Key header gets the original response replayed instead of
 * the request re-executing; a client that doesn't send one (the header is
 * optional) sees no behavior change at all.
 *
 * This is check-then-act, not an atomic claim: two requests carrying the
 * same key that arrive genuinely concurrently (not the sequential
 * timeout-then-retry this exists for) can both pass the "no existing key"
 * check and both execute before either finishes recording it, producing
 * two real records. Closing that window needs a reservation row inserted
 * before $next() runs, plus a way to recover a claim abandoned by a crashed
 * request — deliberately out of scope here; the sequential-retry case this
 * is built for doesn't hit it.
 */
class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key) {
            return $next($request);
        }

        $existing = IdempotencyKey::where('key', $key)
            ->where('user_id', $request->user()->id)
            ->where('route', $request->path())
            ->first();

        if ($existing) {
            return response()->json($existing->response_body, $existing->response_status)
                ->header('Idempotent-Replayed', 'true');
        }

        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            try {
                IdempotencyKey::create([
                    'key' => $key,
                    'user_id' => $request->user()->id,
                    'route' => $request->path(),
                    'response_status' => $response->getStatusCode(),
                    'response_body' => json_decode($response->getContent(), true),
                ]);
            } catch (QueryException) {
                // Two near-simultaneous retries raced to record the same
                // key — whichever lost has nothing to do, since the winner
                // already cached the response this request also computed.
            }
        }

        return $response;
    }
}
