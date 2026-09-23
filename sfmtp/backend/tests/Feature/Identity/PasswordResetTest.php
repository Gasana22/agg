<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Models\RefreshToken;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    public function test_forgot_password_never_reveals_whether_an_account_exists(): void
    {
        Notification::fake();
        $user = $this->member(['email' => 'amina@example.com']);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'amina@example.com'])->assertStatus(202);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'ghost@example.com'])->assertStatus(202);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            return str_starts_with($n->toMail($user)->actionUrl, config('sfmtp.web_url').'/reset-password?token=');
        });
    }

    public function test_reset_changes_the_password_and_signs_out_everywhere(): void
    {
        $user = $this->member(['email' => 'amina@example.com']);
        $this->asUser($user)->getJson('/api/v1/me')->assertOk();
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'amina@example.com',
            'token' => $token,
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])->assertNoContent();

        $this->assertSame(0, RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count());
        $this->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'NewPassw0rd!'])->assertOk();
    }

    public function test_invalid_reset_token_is_rejected(): void
    {
        $this->member(['email' => 'amina@example.com']);

        $this->assertProblem($this->postJson('/api/v1/auth/password/reset', [
            'email' => 'amina@example.com',
            'token' => 'bogus',
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ]), 422, 'invalid_reset_token');
    }
}
