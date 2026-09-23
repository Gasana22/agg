<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Domain\Models\FarmStatusChange;
use App\Modules\Platform\Notifications\FarmStatusChanged;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Support\Database\AppendOnlyViolation;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FarmAdministrationTest extends TestCase
{
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->farm = $this->farm();
        $this->farm->forceFill(['status' => 'pending', 'approved_at' => null])->save();
    }

    private function admin(string $path, array $body = [], array $roles = ['super_admin'])
    {
        return $this->asUser($this->platformAdmin($roles))->postJson("/api/v1/admin/farms/{$this->farm->id}{$path}", $body);
    }

    public function test_approving_a_farm_activates_it_records_history_and_tells_the_owner(): void
    {
        $this->admin('/approve')->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.history.0.to_status', 'active');

        $this->assertNotNull($this->farm->refresh()->approved_at);
        Notification::assertSentTo($this->ownerOf($this->farm), FarmStatusChanged::class, fn ($n) => $n->status === 'active');

        // The owner can see what the platform did, in their own audit log.
        $actions = array_column($this->asUser($this->ownerOf($this->farm))->getJson("/api/v1/farms/{$this->farm->id}/audit-logs")->json('data'), 'action');
        $this->assertContains('admin.farm_status_changed', $actions);

        $this->assertProblem($this->admin('/approve'), 409, 'invalid_state_transition');
    }

    public function test_suspension_blocks_every_member_until_lifted(): void
    {
        $this->admin('/approve');
        $agronomist = $this->memberWithRole($this->farm, 'agronomist');

        $this->admin('/suspend', ['reason_code' => 'policy_violation', 'note' => 'Reported misuse'])
            ->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.suspension_reason', 'policy_violation');

        $this->assertProblem($this->asUser($agronomist)->getJson("/api/v1/farms/{$this->farm->id}/traceability/batches"), 403, 'farm_suspended');
        $this->assertProblem($this->asUser($this->ownerOf($this->farm))->getJson("/api/v1/farms/{$this->farm->id}"), 403, 'farm_suspended');

        $this->admin('/unsuspend', ['note' => 'Resolved'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->asUser($agronomist)->getJson("/api/v1/farms/{$this->farm->id}/traceability/batches")->assertOk();
        $this->assertSame(3, FarmStatusChange::where('farm_id', $this->farm->id)->count());
    }

    public function test_unsuspending_a_never_approved_farm_returns_it_to_pending(): void
    {
        $this->admin('/suspend', ['reason_code' => 'security']);

        $this->admin('/unsuspend')->assertOk()->assertJsonPath('data.status', 'pending');
    }

    public function test_billing_staff_may_only_suspend_for_non_payment(): void
    {
        $this->admin('/approve');

        $this->assertProblem($this->admin('/suspend', ['reason_code' => 'policy_violation'], ['billing']), 403, 'forbidden');
        $this->admin('/suspend', ['reason_code' => 'non_payment'], ['billing'])->assertOk();
        $this->admin('/unsuspend', [], ['billing'])->assertOk()->assertJsonPath('data.status', 'active');

        $this->admin('/suspend', ['reason_code' => 'security']);
        $this->assertProblem($this->admin('/unsuspend', [], ['billing']), 403, 'forbidden');
    }

    public function test_suspension_needs_a_known_reason(): void
    {
        $this->admin('/suspend', ['reason_code' => 'bad_vibes'])->assertUnprocessable()->assertJsonValidationErrors('reason_code');
    }

    public function test_owner_password_reset_sends_a_link_and_reveals_nothing(): void
    {
        $this->admin('/reset-owner-password', [], ['support'])
            ->assertStatus(202)
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.password');

        Notification::assertSentTo($this->ownerOf($this->farm), ResetPassword::class);
    }

    public function test_farm_detail_is_metadata_only(): void
    {
        $data = $this->asUser($this->platformAdmin(['support']))->getJson("/api/v1/admin/farms/{$this->farm->id}")->assertOk()->json('data');

        $this->assertEqualsCanonicalizing([
            'id', 'type', 'code', 'name', 'status', 'suspension_reason', 'district', 'country', 'size_ha', 'organization',
            'owner', 'member_count', 'subscription', 'history', 'approved_at', 'suspended_at', 'created_at',
        ], array_keys($data));
        $this->assertSame('trialing', $data['subscription']['status']);
        $this->assertSame(1, $data['member_count']);
    }

    public function test_listing_filters_by_status_and_search(): void
    {
        $other = $this->farm(attributes: ['name' => 'Kigezi Highlands']);
        $admin = $this->platformAdmin();

        $this->asUser($admin)->getJson('/api/v1/admin/farms?filter[status]=pending')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->farm->id);
        $this->asUser($admin)->getJson('/api/v1/admin/farms?q=kigezi')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other->id);
    }

    public function test_status_history_is_append_only(): void
    {
        $this->admin('/approve');

        $this->expectException(AppendOnlyViolation::class);
        FarmStatusChange::first()->update(['note' => 'rewritten']);
    }
}
