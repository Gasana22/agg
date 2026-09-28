<?php

namespace App\Support\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers every API response carries (docs/11 §2). The API serves JSON and
 * files, never pages, so nothing may be framed, sniffed or run as a page.
 * Responses to a signed-in request are never stored by shared caches.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Cross-Origin-Resource-Policy', 'same-site');
        // JSON is never rendered; files (photos, PDFs) keep the browser's viewer working.
        if (str_contains((string) $headers->get('Content-Type'), 'json') && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if ($request->bearerToken() !== null && ! $headers->hasCacheControlDirective('public')) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
