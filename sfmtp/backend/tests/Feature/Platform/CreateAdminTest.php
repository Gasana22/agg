<?php

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Platform\Application\PlatformPermissions;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    public function test_the_first_administrator_is_created_without_a_usable_password(): void
    {
        Notification::fake();

        $this->artisan('platform:create-admin', ['email' => 'Ops@Example.com', 'name' => 'Ops Lead'])->assertSuccessful();

        $admin = User::where('email', 'ops@example.com')->firstOrFail();
        $this->assertTrue($admin->isPlatformAdmin());
        $this->assertTrue($this->app->make(PlatformPermissions::class)->allows($admin, 'users.manage'));
        Notification::assertSentTo($admin, ResetPassword::class);

        // Nobody knows the password until the link is used.
        $this->postJson('/api/v1/auth/login', ['email' => 'ops@example.com', 'password' => 'Password123!'])->assertStatus(401);

        $this->artisan('platform:create-admin', ['email' => 'ops@example.com', 'name' => 'Again'])->assertFailed();
        $this->artisan('platform:create-admin', ['email' => 'x@example.com', 'name' => 'X', '--role' => 'owner'])->assertExitCode(2);
    }
}
