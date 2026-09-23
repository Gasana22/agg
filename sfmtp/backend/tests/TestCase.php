<?php

namespace Tests;

use App\Modules\Access\Domain\Models\FarmRole;
use App\Modules\Identity\Application\TokenService;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Platform\Application\PlatformPermissions;
use App\Modules\Tenancy\Application\FarmService;
use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Sign in with a real JWT (the guard is exercised on every request). */
    protected function asUser(User $user, string $client = 'web'): static
    {
        $this->app['auth']->forgetGuards();
        $tokens = $this->app->make(TokenService::class)->issue($user, $client, null);

        return $this->withToken($tokens->accessToken);
    }

    protected function member(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    /** A user whose role requires MFA, with MFA already enrolled. */
    protected function withMfa(User $user): User
    {
        $user->forceFill(['mfa_enabled_at' => now()])->save();

        return $user;
    }

    /** Create an active farm through the real service (owner, roles, settings). */
    protected function farm(?User $owner = null, array $attributes = []): Farm
    {
        $owner ??= $this->withMfa($this->member());
        $farm = $this->app->make(FarmService::class)->create($owner, $attributes + ['name' => fake()->company().' Farm', 'size_ha' => 42]);
        $farm->forceFill(['status' => FarmStatus::Active])->save();

        return $farm;
    }

    protected function ownerOf(Farm $farm): User
    {
        return FarmUser::where('farm_id', $farm->id)->where('is_owner', true)->firstOrFail()->user;
    }

    /** Add a member holding the given role template to a farm. */
    protected function memberWithRole(Farm $farm, string $roleKey, ?User $user = null): User
    {
        $user ??= $this->member();

        $this->inFarm($farm, function () use ($farm, $user, $roleKey) {
            $membership = $this->app->make(FarmService::class)->addMember($farm, $user);
            DB::table('farm_user_roles')->insert([
                'farm_id' => $farm->id,
                'farm_user_id' => $membership->id,
                'farm_role_id' => FarmRole::where('key', $roleKey)->value('id'),
                'created_at' => now(),
            ]);
        });

        return in_array($roleKey, config('sfmtp.security.mfa_required_farm_roles'), true) ? $this->withMfa($user) : $user;
    }

    /** A platform staff member holding the given platform roles, MFA enrolled. */
    protected function platformAdmin(array $roles = ['super_admin']): User
    {
        $user = $this->withMfa($this->member(['user_type' => 'platform_admin']));
        foreach ($roles as $role) {
            $this->app->make(PlatformPermissions::class)->assign($user, $role);
        }

        return $user;
    }

    protected function inFarm(Farm $farm, callable $callback): mixed
    {
        return $this->app->make(TenantContext::class)->run($farm, $callback(...));
    }

    protected function assertProblem($response, int $status, string $code): void
    {
        $response->assertStatus($status)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', $code);
    }

    protected function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
