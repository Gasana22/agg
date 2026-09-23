<?php

namespace Tests\Feature\Access;

use App\Modules\Access\Domain\Models\FarmInvitation;
use App\Modules\Access\Domain\Models\FarmRole;
use App\Modules\Access\Notifications\FarmInvitationSent;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->farm = $this->farm();
    }

    /** Drop the previous request's token and the guard's cached user. */
    private function signedOut(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function roleId(string $key): string
    {
        return $this->inFarm($this->farm, fn () => FarmRole::where('key', $key)->value('id'));
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}{$path}";
    }

    /** Invite as the owner and return the emailed token. */
    private function invite(string $email, string $role = 'agronomist'): string
    {
        $this->asUser($this->ownerOf($this->farm))
            ->postJson($this->url('/invitations'), ['email' => $email, 'role_ids' => [$this->roleId($role)], 'message' => 'Welcome!'])
            ->assertCreated();

        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, FarmInvitationSent::class, function (FarmInvitationSent $n, $channels, $notifiable) use ($email, &$token) {
            if (($notifiable->routes['mail'] ?? null) === mb_strtolower($email)) {
                $token = $n->token;
            }

            return true;
        });
        $this->signedOut();

        return $token;
    }

    public function test_invitee_without_an_account_signs_up_and_joins_with_the_invited_roles(): void
    {
        $token = $this->invite('New.Person@Example.com');

        $this->signedOut()->getJson("/api/v1/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('data.email', 'new.person@example.com')
            ->assertJsonPath('data.farm.id', $this->farm->id)
            ->assertJsonPath('data.roles', ['Agronomist'])
            ->assertJsonPath('data.account_exists', false);

        $this->signedOut()->postJson("/api/v1/invitations/{$token}/accept", ['name' => 'New Person', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->signedOut()->postJson("/api/v1/invitations/{$token}/accept", ['name' => 'New Person', 'password' => 'Harvest2026!', 'password_confirmation' => 'Harvest2026!'])
            ->assertCreated()
            ->assertJsonPath('data.farm_id', $this->farm->id)
            ->assertJsonPath('data.account_created', true);

        // They can sign in and hold the agronomist's permissions.
        $this->postJson('/api/v1/auth/login', ['email' => 'new.person@example.com', 'password' => 'Harvest2026!', 'client' => 'web'])->assertOk();
        $user = FarmUser::where('farm_id', $this->farm->id)->whereHas('user', fn ($q) => $q->where('email', 'new.person@example.com'))->firstOrFail()->user;
        $this->assertNotNull($user->email_verified_at);
        $this->asUser($user)->getJson($this->url('/structure'))->assertOk();
        $this->assertProblem($this->asUser($user)->getJson($this->url('/invitations')), 403, 'forbidden');

        // The link is single-use.
        $this->signedOut();
        $this->assertProblem($this->signedOut()->postJson("/api/v1/invitations/{$token}/accept", ['name' => 'Xavier', 'password' => 'Harvest2026!', 'password_confirmation' => 'Harvest2026!']), 410, 'invitation_used');
    }

    public function test_existing_account_must_sign_in_with_the_invited_email(): void
    {
        $existing = $this->member(['email' => 'grower@example.com']);
        $token = $this->invite('grower@example.com');

        $this->signedOut()->getJson("/api/v1/invitations/{$token}")->assertJsonPath('data.account_exists', true);
        $this->assertProblem($this->signedOut()->postJson("/api/v1/invitations/{$token}/accept"), 409, 'sign_in_required');

        $someoneElse = $this->member();
        $this->assertProblem($this->asUser($someoneElse)->postJson("/api/v1/invitations/{$token}/accept"), 403, 'invitation_email_mismatch');

        $this->asUser($existing)->postJson("/api/v1/invitations/{$token}/accept")
            ->assertOk()->assertJsonPath('data.account_created', false);
        $this->asUser($existing)->getJson('/api/v1/me/workspaces')->assertJsonPath('data.0.id', $this->farm->id);
    }

    public function test_expired_revoked_and_unknown_invitations(): void
    {
        $token = $this->invite('late@example.com');
        $this->travel(8)->days();
        $this->assertProblem($this->signedOut()->postJson("/api/v1/invitations/{$token}/accept", ['name' => 'Late', 'password' => 'Harvest2026!', 'password_confirmation' => 'Harvest2026!']), 410, 'invitation_expired');
        $this->travelBack();

        $token = $this->invite('withdrawn@example.com');
        $id = $this->inFarm($this->farm, fn () => FarmInvitation::where('email', 'withdrawn@example.com')->value('id'));
        $this->asUser($this->ownerOf($this->farm))->deleteJson($this->url("/invitations/{$id}"))->assertNoContent();
        $this->signedOut();
        $this->assertProblem($this->signedOut()->postJson("/api/v1/invitations/{$token}/accept", ['name' => 'Withdrawn', 'password' => 'Harvest2026!', 'password_confirmation' => 'Harvest2026!']), 410, 'invitation_revoked');

        $this->assertProblem($this->signedOut()->getJson('/api/v1/invitations/'.str_repeat('a', 48)), 404, 'not_found');
    }

    public function test_resend_rotates_the_token_and_duplicates_are_refused(): void
    {
        $old = $this->invite('twice@example.com');
        $owner = $this->ownerOf($this->farm);

        $this->assertProblem(
            $this->asUser($owner)->postJson($this->url('/invitations'), ['email' => 'TWICE@example.com', 'role_ids' => [$this->roleId('agronomist')]]),
            409, 'duplicate',
        );

        $id = $this->inFarm($this->farm, fn () => FarmInvitation::where('email', 'twice@example.com')->value('id'));
        $this->asUser($owner)->postJson($this->url("/invitations/{$id}/resend"))->assertOk()->assertJsonPath('data.send_count', 2);
        $this->signedOut();
        $this->signedOut()->getJson("/api/v1/invitations/{$old}")->assertNotFound();

        $this->asUser($owner)->getJson($this->url('/invitations'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'pending');
    }

    public function test_managers_may_invite_field_workers_only(): void
    {
        $manager = $this->memberWithRole($this->farm, 'manager');

        $this->asUser($manager)->postJson($this->url('/invitations'), ['email' => 'hand@example.com', 'role_ids' => [$this->roleId('field_worker')]])
            ->assertCreated();
        $this->assertProblem(
            $this->asUser($manager)->postJson($this->url('/invitations'), ['email' => 'acc@example.com', 'role_ids' => [$this->roleId('accountant')]]),
            403, 'forbidden',
        );
        $this->assertProblem(
            $this->asUser($this->memberWithRole($this->farm, 'agronomist'))->postJson($this->url('/invitations'), ['email' => 'x@example.com', 'role_ids' => [$this->roleId('field_worker')]]),
            403, 'forbidden',
        );
        $this->assertProblem(
            $this->asUser($this->ownerOf($this->farm))->postJson($this->url('/invitations'), ['email' => 'boss@example.com', 'role_ids' => [$this->roleId('owner')]]),
            422, 'guard_rail_owner_role',
        );
    }

    public function test_platform_staff_emails_cannot_be_invited(): void
    {
        $staff = $this->platformAdmin(['support']);

        $this->asUser($this->ownerOf($this->farm))
            ->postJson($this->url('/invitations'), ['email' => $staff->email, 'role_ids' => [$this->roleId('agronomist')]])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_plan_user_limit_applies_when_inviting_and_accepting(): void
    {
        // Starter allows 10 users: the owner plus 8 members leaves one seat.
        DB::table('subscriptions')->where('organization_id', $this->farm->organization_id)
            ->update(['plan_id' => DB::table('subscription_plans')->where('code', 'starter')->value('id')]);
        for ($i = 0; $i < 8; $i++) {
            $this->memberWithRole($this->farm, 'field_worker');
        }

        $first = $this->invite('seat-a@example.com');
        $second = $this->invite('seat-b@example.com');   // pending invitations do not hold seats

        $body = ['name' => 'Seat', 'password' => 'Harvest2026!', 'password_confirmation' => 'Harvest2026!'];
        $this->signedOut()->postJson("/api/v1/invitations/{$first}/accept", $body)->assertCreated();
        $this->assertProblem($this->signedOut()->postJson("/api/v1/invitations/{$second}/accept", $body), 403, 'plan_limit_reached');

        $this->assertProblem(
            $this->asUser($this->ownerOf($this->farm))->postJson($this->url('/invitations'), ['email' => 'seat-c@example.com', 'role_ids' => [$this->roleId('agronomist')]]),
            403, 'plan_limit_reached',
        );
    }

    public function test_owner_changes_roles_suspends_and_removes_members(): void
    {
        $owner = $this->ownerOf($this->farm);
        $worker = $this->memberWithRole($this->farm, 'field_worker');
        $memberId = FarmUser::where('farm_id', $this->farm->id)->where('user_id', $worker->id)->value('id');

        $this->asUser($owner)->patchJson($this->url("/members/{$memberId}"), ['role_ids' => [$this->roleId('store_manager'), $this->roleId('livestock_manager')]])
            ->assertOk()->assertJsonCount(2, 'data.roles');
        $this->asUser($worker)->getJson($this->url('/dashboards/store'))->assertOk();

        $this->asUser($owner)->patchJson($this->url("/members/{$memberId}"), ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertProblem($this->asUser($worker)->getJson($this->url('/structure')), 404, 'not_found');

        $this->asUser($owner)->patchJson($this->url("/members/{$memberId}"), ['status' => 'active'])->assertOk();
        $this->asUser($worker)->getJson($this->url('/structure'))->assertOk();

        $this->asUser($owner)->deleteJson($this->url("/members/{$memberId}"))->assertNoContent();
        $this->assertProblem($this->asUser($worker)->getJson($this->url('/structure')), 404, 'not_found');

        $actions = $this->inFarm($this->farm, fn () => DB::table('audit_logs')->where('entity_id', $memberId)->orderBy('created_at')->pluck('action')->all());
        $this->assertSame(['member.roles_changed', 'member.status_changed', 'member.status_changed', 'member.removed'], $actions);
    }

    public function test_member_guard_rails(): void
    {
        $owner = $this->ownerOf($this->farm);
        $ownerMembership = FarmUser::where('farm_id', $this->farm->id)->where('is_owner', true)->value('id');

        $this->assertProblem($this->asUser($owner)->deleteJson($this->url("/members/{$ownerMembership}")), 403, 'guard_rail_owner');

        $manager = $this->memberWithRole($this->farm, 'manager');
        $managerMembership = FarmUser::where('user_id', $manager->id)->value('id');
        $this->assertProblem($this->asUser($manager)->patchJson($this->url("/members/{$managerMembership}"), ['status' => 'suspended']), 403, 'forbidden');

        $this->assertProblem(
            $this->asUser($owner)->patchJson($this->url("/members/{$managerMembership}"), ['role_ids' => [$this->roleId('owner')]]),
            422, 'guard_rail_owner_role',
        );
    }

    public function test_owner_creates_edits_and_deletes_custom_roles(): void
    {
        $owner = $this->ownerOf($this->farm);

        $role = $this->asUser($owner)->postJson($this->url('/roles'), [
            'name' => 'Dairy Supervisor', 'copy_from_role_id' => $this->roleId('livestock_manager'),
        ])->assertCreated()
            ->assertJsonPath('data.key', 'dairy_supervisor')
            ->assertJsonPath('data.is_system', false)
            ->json('data');
        $this->assertSame('all', $role['grants']['livestock.animals.manage']);

        $this->assertProblem($this->asUser($owner)->postJson($this->url('/roles'), ['name' => 'Dairy supervisor']), 409, 'duplicate');

        // Copying the owner role never copies owner-only permissions.
        $deputy = $this->asUser($owner)->postJson($this->url('/roles'), ['name' => 'Deputy', 'copy_from_role_id' => $this->roleId('owner')])
            ->assertCreated()->json('data.grants');
        $this->assertArrayHasKey('finance.approve', $deputy);
        $this->assertArrayNotHasKey('billing.manage', $deputy);

        $this->asUser($owner)->patchJson($this->url("/roles/{$role['id']}"), ['description' => 'Milking parlour lead'])
            ->assertOk()->assertJsonPath('data.description', 'Milking parlour lead');

        // In use: cannot delete.
        $user = $this->member();
        $this->memberWithRole($this->farm, 'dairy_supervisor', $user);
        $this->assertProblem($this->asUser($owner)->deleteJson($this->url("/roles/{$role['id']}")), 409, 'role_in_use');

        $membership = FarmUser::where('user_id', $user->id)->value('id');
        $this->asUser($owner)->patchJson($this->url("/members/{$membership}"), ['role_ids' => [$this->roleId('livestock_manager')]])->assertOk();
        $this->asUser($owner)->deleteJson($this->url("/roles/{$role['id']}"))->assertNoContent();

        $this->assertProblem($this->asUser($owner)->deleteJson($this->url('/roles/'.$this->roleId('agronomist'))), 403, 'system_role');
        $this->assertProblem($this->asUser($owner)->patchJson($this->url('/roles/'.$this->roleId('owner')), ['name' => 'Boss']), 403, 'role_locked');

        $manager = $this->memberWithRole($this->farm, 'manager');
        $this->assertProblem($this->asUser($manager)->postJson($this->url('/roles'), ['name' => 'Sneaky']), 403, 'forbidden');
    }

    public function test_farm_settings(): void
    {
        $owner = $this->ownerOf($this->farm);

        $this->asUser($owner)->getJson($this->url('/settings'))
            ->assertOk()->assertJsonPath('data.require_mfa_for_all', false)->assertJsonPath('data.units', 'metric');

        $this->asUser($owner)->patchJson($this->url('/settings'), [
            'approval_thresholds' => ['expense' => 500000],
            'allow_negative_stock' => true,
        ])->assertOk()
            ->assertJsonPath('data.approval_thresholds.expense', 500000)
            ->assertJsonPath('data.approval_thresholds.purchase_order', null)
            ->assertJsonPath('data.allow_negative_stock', true);

        $this->asUser($owner)->patchJson($this->url('/settings'), ['approval_thresholds' => ['stock_adjustment_pct' => 120], 'units' => 'furlongs'])
            ->assertStatus(422)->assertJsonValidationErrors(['approval_thresholds.stock_adjustment_pct', 'units']);

        // Requiring MFA for everyone takes effect on members' next request.
        $agronomist = $this->memberWithRole($this->farm, 'agronomist');
        $this->asUser($owner)->patchJson($this->url('/settings'), ['require_mfa_for_all' => true])->assertOk();
        $this->assertProblem($this->asUser($agronomist)->getJson($this->url('/structure')), 403, 'mfa_setup_required');

        $manager = $this->memberWithRole($this->farm, 'manager');
        $this->withMfa($manager);
        $this->asUser($manager)->getJson($this->url('/settings'))->assertOk();
        $this->assertProblem($this->asUser($manager)->patchJson($this->url('/settings'), ['units' => 'imperial']), 403, 'forbidden');

        $this->assertSame(2, $this->inFarm($this->farm, fn () => DB::table('audit_logs')->where('action', 'farm.settings_changed')->count()));
    }
}
