<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Models\RefreshToken;
use App\Modules\Identity\Domain\Models\User;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    private function login(string $email, string $password = 'password', array $extra = [])
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password] + $extra);
    }

    public function test_login_returns_access_and_refresh_tokens(): void
    {
        $user = $this->member(['email' => 'amina@example.com']);

        $response = $this->login('AMINA@example.com ')->assertOk()
            ->assertJsonPath('data.mfa_required', false)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.expires_in', 900);

        $this->withToken($response->json('data.access_token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_wrong_password_and_unknown_email_get_the_same_answer(): void
    {
        $this->member(['email' => 'amina@example.com']);

        $this->assertProblem($this->login('amina@example.com', 'wrong'), 401, 'invalid_credentials');
        $this->assertProblem($this->login('nobody@example.com', 'wrong'), 401, 'invalid_credentials');
    }

    public function test_account_locks_after_repeated_failures(): void
    {
        $user = $this->member(['email' => 'amina@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])->login('amina@example.com', 'wrong');
        }

        $this->assertNotNull($user->refresh()->locked_until);
        // Even the right password is refused while locked, with the generic answer.
        $this->assertProblem($this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])->login('amina@example.com'), 401, 'invalid_credentials');
    }

    public function test_disabled_users_cannot_sign_in_or_use_existing_tokens(): void
    {
        $user = $this->member(['email' => 'amina@example.com']);
        $this->asUser($user)->getJson('/api/v1/me')->assertOk();

        $user->forceFill(['status' => 'disabled'])->save();

        $this->assertProblem($this->login('amina@example.com'), 401, 'invalid_credentials');
        $this->asUser($user)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_refresh_rotates_the_token(): void
    {
        $this->member(['email' => 'amina@example.com']);
        $first = $this->login('amina@example.com')->json('data');

        $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->assertOk()->json('data');

        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->app['auth']->forgetGuards();
        $this->withToken($second['access_token'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_reusing_a_rotated_refresh_token_revokes_the_whole_session(): void
    {
        $user = $this->member(['email' => 'amina@example.com']);
        $first = $this->login('amina@example.com')->json('data');
        $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->json('data');

        // Attacker replays the old token.
        $this->assertProblem($this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']]), 401, 'token_reuse_detected');

        // The legitimate holder's newer tokens are dead too.
        $this->assertProblem($this->postJson('/api/v1/auth/refresh', ['refresh_token' => $second['refresh_token']]), 401, 'invalid_refresh_token');
        $this->app['auth']->forgetGuards();
        $this->withToken($second['access_token'])->getJson('/api/v1/me')->assertUnauthorized();

        $this->assertSame(0, RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.refresh_token_reuse', 'user_id' => $user->id]);
    }

    public function test_logout_invalidates_the_access_token_immediately(): void
    {
        $this->member(['email' => 'amina@example.com']);
        $tokens = $this->login('amina@example.com')->json('data');

        $this->withToken($tokens['access_token'])->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->withToken($tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertProblem($this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']]), 401, 'invalid_refresh_token');
    }

    public function test_tampered_or_foreign_tokens_are_rejected(): void
    {
        $user = $this->member();
        $token = $this->login($user->email)->json('data.access_token');

        [$h, $p, $s] = explode('.', $token);
        $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        $claims['sub'] = User::factory()->create()->id;
        $forged = $h.'.'.rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.'.$s;

        $this->app['auth']->forgetGuards();
        $this->withToken($forged)->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken('not-a-jwt')->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_devices_can_be_listed_and_revoked(): void
    {
        $user = $this->member(['email' => 'amina@example.com']);
        $mobile = $this->login('amina@example.com', 'password', ['client' => 'mobile', 'device' => ['name' => 'Tecno Spark', 'platform' => 'android']])->json('data');
        $web = $this->login('amina@example.com')->json('data');

        $devices = $this->withToken($web['access_token'])->getJson('/api/v1/me/devices')->assertOk()->json('data');
        $this->assertCount(2, $devices);
        $phone = collect($devices)->firstWhere('name', 'Tecno Spark');

        $this->withToken($web['access_token'])->deleteJson("/api/v1/me/devices/{$phone['id']}")->assertNoContent();

        $this->assertProblem($this->postJson('/api/v1/auth/refresh', ['refresh_token' => $mobile['refresh_token']]), 401, 'invalid_refresh_token');
    }
}
