<?php

namespace Tests\Feature\Security;

use App\Modules\Identity\Application\TokenService;
use Firebase\JWT\JWT;
use Tests\TestCase;

/**
 * Phase 15 security checks that run on every build (docs/11-security.md):
 * response headers, cross-origin rules, forged or replayed tokens, the
 * token type boundary, and hostile input on list endpoints.
 */
class SecurityTest extends TestCase
{
    public function test_every_api_response_carries_the_security_headers(): void
    {
        $owner = $this->ownerOf($this->farm());

        foreach ([$this->getJson('/api/v1/me'), $this->asUser($owner)->getJson('/api/v1/me')] as $response) {
            $response->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }

        // Signed-in answers are never kept by a shared cache.
        $this->assertStringContainsString('no-store', $this->asUser($owner)->getJson('/api/v1/me')->headers->get('Cache-Control'));

        // HSTS once served over TLS.
        $this->get('https://localhost/api/v1/me', ['Accept' => 'application/json'])
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_only_the_web_app_origin_may_call_the_api_from_a_browser(): void
    {
        $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/v1/auth/login', server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertSame(config('sfmtp.web_url'), $preflight(config('sfmtp.web_url'))->headers->get('Access-Control-Allow-Origin'));
        // Any other origin never gets itself (or a wildcard) back, so the browser blocks it.
        $this->assertNotContains($preflight('https://evil.example')->headers->get('Access-Control-Allow-Origin'), ['https://evil.example', '*']);
    }

    public function test_forged_expired_and_revoked_tokens_are_refused(): void
    {
        $owner = $this->ownerOf($this->farm());
        $tokens = $this->app->make(TokenService::class)->issue($owner, 'web', null);
        [$header, $payload] = explode('.', $tokens->accessToken);
        $claims = json_decode(JWT::urlsafeB64Decode($payload), true);

        $refused = function (string $jwt) {
            $this->app['auth']->forgetGuards();
            $this->assertProblem($this->withToken($jwt)->getJson('/api/v1/me'), 401, 'unauthenticated');
        };

        // "alg": "none", no signature.
        $refused(JWT::urlsafeB64Encode('{"alg":"none","typ":"JWT"}').'.'.$payload.'.');
        // Claims changed after signing (another user).
        $refused($header.'.'.JWT::urlsafeB64Encode(json_encode(['sub' => $this->member()->id] + $claims)).'.'.explode('.', $tokens->accessToken)[2]);
        // Signed with another key.
        $refused(JWT::encode($claims, str_repeat('k', 40), 'HS256'));
        // Expired.
        $refused(JWT::encode(['exp' => time() - 60, 'iat' => time() - 1000] + $claims, (string) config('sfmtp.jwt.secret'), 'HS256'));
        // A pending-MFA token is not an access token.
        $refused($this->app->make(TokenService::class)->issueMfaToken($owner, 'web', []));

        // Signing out ends the session: the same access token stops working.
        $this->app['auth']->forgetGuards();
        $this->withToken($tokens->accessToken)->getJson('/api/v1/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($tokens->accessToken)->postJson('/api/v1/auth/logout', ['refresh_token' => $tokens->refreshToken]);
        $refused($tokens->accessToken);
    }

    public function test_hostile_input_on_list_endpoints_is_data_not_sql(): void
    {
        $farm = $this->farm();
        $owner = $this->ownerOf($farm);

        foreach (["' OR 1=1 --", '%_%', "'; DROP TABLE animals; --", str_repeat('ü', 100)] as $q) {
            $this->asUser($owner)->getJson("/api/v1/farms/{$farm->id}/animals?q=".urlencode($q))->assertOk()->assertJsonCount(0, 'data');
            $this->asUser($owner)->getJson('/api/v1/catalog/crops?q='.urlencode($q))->assertOk();
        }

        // Oversized or wrongly typed filters are rejected, not passed to the database.
        $this->assertProblem($this->asUser($owner)->getJson("/api/v1/farms/{$farm->id}/animals?q=".str_repeat('a', 101)), 422, 'validation_failed');
        $this->assertProblem($this->asUser($owner)->getJson('/api/v1/catalog/crop-varieties?filter[parent_id]=1%20OR%201=1'), 422, 'validation_failed');
    }
}
