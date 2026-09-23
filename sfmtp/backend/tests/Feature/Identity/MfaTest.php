<?php

namespace Tests\Feature\Identity;

use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MfaTest extends TestCase
{
    private function enrol($user): array
    {
        $secret = $this->asUser($user)->postJson('/api/v1/auth/mfa/setup')->assertOk()->json('data.secret');
        $codes = $this->asUser($user)->postJson('/api/v1/auth/mfa/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertOk()
            ->assertJsonCount(10, 'data.recovery_codes')
            ->json('data.recovery_codes');

        return [$secret, $codes];
    }

    public function test_enrolment_then_login_requires_a_second_factor(): void
    {
        $user = $this->member(['email' => 'amina@example.com']);
        [$secret] = $this->enrol($user);
        $this->assertNotNull($user->refresh()->mfa_enabled_at);

        $first = $this->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.mfa_required', true)
            ->assertJsonMissingPath('data.access_token');

        // The previous step's time window was used during enrolment: wait for the next one.
        $this->travel(31)->seconds();
        $this->postJson('/api/v1/auth/mfa/challenge', [
            'mfa_token' => $first->json('data.mfa_token'),
            'code' => (new Google2FA)->getCurrentOtp($secret),
        ])->assertOk()->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);
    }

    public function test_a_code_cannot_be_replayed(): void
    {
        $user = $this->member(['email' => 'amina@example.com']);
        [$secret] = $this->enrol($user);
        $this->travel(31)->seconds();
        $code = (new Google2FA)->getCurrentOtp($secret);

        $mfaToken = fn () => $this->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'password'])->json('data.mfa_token');

        $this->postJson('/api/v1/auth/mfa/challenge', ['mfa_token' => $mfaToken(), 'code' => $code])->assertOk();
        $this->assertProblem($this->postJson('/api/v1/auth/mfa/challenge', ['mfa_token' => $mfaToken(), 'code' => $code]), 422, 'invalid_mfa_code');
    }

    public function test_recovery_codes_work_exactly_once(): void
    {
        $user = $this->member(['email' => 'amina@example.com']);
        [, $codes] = $this->enrol($user);
        $mfaToken = fn () => $this->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'password'])->json('data.mfa_token');

        $this->postJson('/api/v1/auth/mfa/challenge', ['mfa_token' => $mfaToken(), 'recovery_code' => strtolower($codes[0])])->assertOk();
        $this->assertProblem($this->postJson('/api/v1/auth/mfa/challenge', ['mfa_token' => $mfaToken(), 'recovery_code' => $codes[0]]), 422, 'invalid_mfa_code');
    }

    public function test_farm_owners_must_enrol_before_using_the_farm(): void
    {
        $owner = $this->member();
        $farm = $this->farm($this->withMfa($owner));
        $owner->forceFill(['mfa_enabled_at' => null])->save();

        $this->assertProblem($this->asUser($owner)->getJson("/api/v1/farms/{$farm->id}"), 403, 'mfa_setup_required');

        // Profile, workspaces and enrolment stay reachable.
        $this->asUser($owner)->getJson('/api/v1/me')->assertOk()->assertJsonPath('meta.mfa_required', true);
        $this->asUser($owner)->getJson('/api/v1/me/workspaces')->assertOk()->assertJsonPath('meta.mfa_required', true);
        $this->asUser($owner)->postJson('/api/v1/auth/mfa/setup')->assertOk();
    }

    public function test_platform_admins_must_enrol(): void
    {
        $admin = $this->member(['user_type' => 'platform_admin']);

        $this->assertProblem($this->asUser($admin)->getJson('/api/v1/admin/dashboard'), 403, 'mfa_setup_required');
    }

    public function test_mfa_cannot_be_disabled_when_the_role_requires_it(): void
    {
        $owner = $this->member();
        [$secret] = $this->enrol($owner);
        $this->farm($owner);
        $this->travel(31)->seconds();

        $this->assertProblem(
            $this->asUser($owner)->deleteJson('/api/v1/auth/mfa', ['code' => (new Google2FA)->getCurrentOtp($secret)]),
            403,
            'mfa_required_by_policy',
        );
    }

    public function test_optional_mfa_can_be_disabled_with_a_valid_code(): void
    {
        $user = $this->member();
        [$secret] = $this->enrol($user);
        $this->travel(31)->seconds();

        $this->asUser($user)->deleteJson('/api/v1/auth/mfa', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertNoContent();
        $this->assertNull($user->refresh()->mfa_enabled_at);
    }
}
