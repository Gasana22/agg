<?php

namespace Tests\Feature\Support;

use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use Tests\TestCase;

class SupportTest extends TestCase
{
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->farm = $this->farm();
        $this->inFarm($this->farm, fn () => $this->app->make(Recorder::class)->createBatch(BatchKind::SeedLot, ['name' => 'Seed']));
    }

    private function openTicket($user, array $data = []): array
    {
        return $this->asUser($user)->postJson('/api/v1/support/tickets', $data + [
            'subject' => 'Harvest totals look wrong', 'body' => 'Plot B-3 shows 0 kg.', 'farm_id' => $this->farm->id,
        ])->assertCreated()->json('data');
    }

    public function test_members_open_tickets_and_owners_see_their_organizations_tickets(): void
    {
        $agronomist = $this->memberWithRole($this->farm, 'agronomist');
        $worker = $this->memberWithRole($this->farm, 'field_worker');

        $ticket = $this->openTicket($agronomist);
        $this->assertMatchesRegularExpression('/^SUP-[2-9A-Z]{6}$/', $ticket['reference']);
        $this->assertSame('open', $ticket['status']);

        $this->asUser($this->ownerOf($this->farm))->getJson('/api/v1/support/tickets')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($worker)->getJson('/api/v1/support/tickets')->assertOk()->assertJsonCount(0, 'data');
        $this->assertProblem($this->asUser($worker)->getJson("/api/v1/support/tickets/{$ticket['id']}"), 404, 'not_found');

        $outsider = $this->member();
        $this->asUser($outsider)->postJson('/api/v1/support/tickets', ['subject' => 'x', 'body' => 'y', 'farm_id' => $this->farm->id])
            ->assertUnprocessable()->assertJsonValidationErrors('farm_id');
    }

    public function test_staff_replies_and_internal_notes(): void
    {
        $owner = $this->ownerOf($this->farm);
        $ticket = $this->openTicket($owner);
        $support = $this->platformAdmin(['support']);

        $this->asUser($support)->postJson("/api/v1/admin/support/tickets/{$ticket['id']}/messages", ['body' => 'Looking into it.'])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->asUser($support)->postJson("/api/v1/admin/support/tickets/{$ticket['id']}/messages", ['body' => 'Probably unit conversion.', 'is_internal' => true])->assertCreated();

        $member = $this->asUser($owner)->getJson("/api/v1/support/tickets/{$ticket['id']}")->assertOk()->json('data');
        $this->assertCount(2, $member['messages']);   // opening message + public reply
        $this->assertTrue($member['messages'][1]['author']['is_staff']);

        $staff = $this->asUser($support)->getJson("/api/v1/admin/support/tickets/{$ticket['id']}")->json('data');
        $this->assertCount(3, $staff['messages']);

        $this->asUser($owner)->postJson("/api/v1/support/tickets/{$ticket['id']}/messages", ['body' => 'Thanks!'])->assertCreated()->assertJsonPath('data.status', 'open');
        $this->assertProblem($this->asUser($this->platformAdmin(['billing']))->postJson("/api/v1/admin/support/tickets/{$ticket['id']}/messages", ['body' => 'hi']), 403, 'forbidden');
    }

    public function test_owner_granted_support_access_is_read_only_audited_and_time_boxed(): void
    {
        $owner = $this->ownerOf($this->farm);
        $support = $this->platformAdmin(['support']);
        $ticket = $this->openTicket($owner);
        $base = "/api/v1/farms/{$this->farm->id}";

        // Before a grant: nothing.
        $this->assertSame(404, $this->asUser($support)->getJson("{$base}/traceability/batches")->status());

        $grant = $this->asUser($owner)->postJson("/api/v1/support/tickets/{$ticket['id']}/access-grants", ['hours' => 24])->assertCreated()->json('data');

        $this->asUser($support)->getJson("{$base}/traceability/batches")->assertOk()->assertJsonCount(1, 'data');
        $actions = array_column($this->asUser($support)->getJson("{$base}/dashboards/owner")->assertOk()->json('data.quick_actions'), 'key');
        $this->assertSame(['view_map', 'view_audit_log'], $actions);   // read-only: no "new batch", no "invite"
        $this->assertProblem($this->asUser($support)->postJson("{$base}/traceability/batches", ['kind' => 'processed']), 403, 'support_read_only');

        // A support workspace appears for the staff member; the owner sees the active grant.
        $ws = collect($this->asUser($support)->getJson('/api/v1/me/workspaces')->json('data'))->firstWhere('type', 'support');
        $this->assertSame($this->farm->id, $ws['id']);
        $this->assertArrayNotHasKey('finance.view', $ws['permissions']);
        $this->assertArrayHasKey('trace.batches.view', $ws['permissions']);
        $ownerWs = collect($this->asUser($owner)->getJson('/api/v1/me/workspaces')->json('data'))->firstWhere('id', $this->farm->id);
        $this->assertSame($grant['id'], $ownerWs['support_access']['grant_id']);

        // Every support request is in the farm's audit log.
        $actions = array_column($this->asUser($owner)->getJson("{$base}/audit-logs?filter[action]=support.request")->json('data'), 'new_values');
        $this->assertContainsEquals(['method' => 'GET', 'path' => "api/v1/farms/{$this->farm->id}/traceability/batches"], $actions);   // key order differs on MySQL

        // Super admins have no support.access capability.
        $this->assertSame(404, $this->asUser($this->platformAdmin())->getJson("{$base}/traceability/batches")->status());

        // Expiry.
        $this->travel(25)->hours();
        $this->assertSame(404, $this->asUser($support)->getJson("{$base}/traceability/batches")->status());
    }

    public function test_owners_can_revoke_and_resolving_a_ticket_ends_access(): void
    {
        $owner = $this->ownerOf($this->farm);
        $support = $this->platformAdmin(['support']);
        $ticket = $this->openTicket($owner);
        $base = "/api/v1/farms/{$this->farm->id}/traceability/batches";

        $grant = $this->asUser($owner)->postJson("/api/v1/support/tickets/{$ticket['id']}/access-grants", ['hours' => 4])->json('data');
        $this->asUser($owner)->deleteJson("/api/v1/support/tickets/{$ticket['id']}/access-grants/{$grant['id']}")->assertNoContent();
        $this->assertSame(404, $this->asUser($support)->getJson($base)->status());

        $this->asUser($owner)->postJson("/api/v1/support/tickets/{$ticket['id']}/access-grants", ['hours' => 4])->assertCreated();
        $this->asUser($support)->getJson($base)->assertOk();
        $this->asUser($support)->patchJson("/api/v1/admin/support/tickets/{$ticket['id']}", ['status' => 'resolved'])->assertOk();
        $this->assertSame(404, $this->asUser($support)->getJson($base)->status());
    }

    public function test_grants_are_limited_to_the_owner_and_to_named_staff(): void
    {
        $owner = $this->ownerOf($this->farm);
        $manager = $this->memberWithRole($this->farm, 'manager');
        $ticket = $this->openTicket($manager);
        $alice = $this->platformAdmin(['support']);
        $bob = $this->platformAdmin(['support']);

        $this->assertProblem($this->asUser($manager)->postJson("/api/v1/support/tickets/{$ticket['id']}/access-grants", ['hours' => 4]), 403, 'owner_only');
        $this->asUser($owner)->postJson("/api/v1/support/tickets/{$ticket['id']}/access-grants", ['hours' => 100])->assertUnprocessable();

        // Assigned to Alice: the grant is hers alone.
        $this->asUser($alice)->patchJson("/api/v1/admin/support/tickets/{$ticket['id']}", ['assigned_to' => $alice->id])->assertOk();
        $this->asUser($owner)->postJson("/api/v1/support/tickets/{$ticket['id']}/access-grants", ['hours' => 4])->assertCreated();

        $this->asUser($alice)->getJson("/api/v1/farms/{$this->farm->id}")->assertOk();
        $this->assertSame(404, $this->asUser($bob)->getJson("/api/v1/farms/{$this->farm->id}")->status());
        $this->asUser($alice)->patchJson("/api/v1/admin/support/tickets/{$ticket['id']}", ['assigned_to' => $owner->id])->assertUnprocessable();
    }
}
