<?php

namespace Tests\Feature\Workforce;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Traceability\Domain\Models\TraceEvent;
use App\Modules\Workforce\Domain\Models\TaskLog;
use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkforceTest extends TestCase
{
    private Farm $farm;

    private User $owner;

    private User $manager;

    private User $agronomist;

    private User $keeper;

    private User $fieldWorker;

    private User $otherWorker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->farm = $this->farm();
        $this->owner = $this->ownerOf($this->farm);
        $this->manager = $this->memberWithRole($this->farm, 'manager');
        $this->agronomist = $this->memberWithRole($this->farm, 'agronomist');
        $this->keeper = $this->memberWithRole($this->farm, 'livestock_manager');
        $this->fieldWorker = $this->memberWithRole($this->farm, 'field_worker');
        $this->otherWorker = $this->memberWithRole($this->farm, 'field_worker');
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}{$path}";
    }

    private function as(User $user)
    {
        return $this->asUser($user);
    }

    private function membership(User $user): string
    {
        return FarmUser::where('farm_id', $this->farm->id)->where('user_id', $user->id)->value('id');
    }

    private function worker(?User $user = null, array $data = []): array
    {
        return $this->as($this->manager)->postJson($this->url('/workers'), $data + [
            'full_name' => $user?->name ?? 'Casual Worker', 'employment_type' => 'casual', 'farm_user_id' => $user ? $this->membership($user) : null,
        ])->assertCreated()->json('data');
    }

    private function type(string $code): string
    {
        return DB::table('global_activity_types')->where('code', $code)->value('id');
    }

    private ?string $crop = null;

    private function cycle(): array
    {
        $plot = $this->as($this->owner)->postJson($this->url('/structure/plots'), ['name' => 'Plot '.fake()->unique()->numberBetween(1, 999), 'declared_area_ha' => 2])->assertCreated()->json('data.id');
        $crop = $this->crop ??= $this->as($this->agronomist)->postJson($this->url('/crops'), ['global_variety_id' => DB::table('global_crop_varieties')->where('code', 'longe_5')->value('id')])->json('data.id');

        return $this->as($this->agronomist)->postJson($this->url('/crop-cycles'), ['plot_id' => $plot, 'crop_id' => $crop, 'planted_on' => now()->subDays(30)->toDateString()])
            ->assertCreated()->json('data');
    }

    private function activity(User $by, array $data): array
    {
        return $this->as($by)->postJson($this->url('/activities'), $data)->assertCreated()->json('data');
    }

    public function test_assign_execute_submit_verify_with_trace_history(): void
    {
        $cycle = $this->cycle();
        $worker = $this->worker($this->fieldWorker);
        $this->assertSame('WRK-001', $worker['worker_code']);

        $activity = $this->activity($this->agronomist, [
            'activity_type_id' => $this->type('weeding'), 'subject_type' => 'crop_cycle', 'subject_id' => $cycle['id'],
            'instructions' => 'Weed between the rows', 'target_quantity' => 2, 'target_unit' => 'ha', 'worker_ids' => [$worker['id']],
        ]);
        $this->assertSame('ACT-001', $activity['code']);
        $this->assertSame('crops', $activity['module']);
        $this->assertStringStartsWith('Weeding · CC-001', $activity['title']);
        $task = $activity['tasks'][0];
        $this->assertSame(['TSK-001', 'assigned'], [$task['code'], $task['status']]);

        // The worker sees the task and works it: start, pause, resume, submit with location.
        $this->as($this->fieldWorker)->getJson($this->url('/tasks'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.activity.instructions', 'Weed between the rows');
        $point = ['lat' => 0.3476, 'lng' => 32.5825, 'accuracy_m' => 6];
        $this->as($this->fieldWorker)->postJson($this->url("/tasks/{$task['id']}/start"), $point + ['occurred_at' => now()->subMinutes(150)->toIso8601String()])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->as($this->fieldWorker)->postJson($this->url("/tasks/{$task['id']}/pause"), ['occurred_at' => now()->subMinutes(90)->toIso8601String()])->assertOk()->assertJsonPath('data.status', 'paused');
        $this->as($this->fieldWorker)->postJson($this->url("/tasks/{$task['id']}/resume"), ['occurred_at' => now()->subMinutes(60)->toIso8601String()])->assertOk();
        $submitted = $this->as($this->fieldWorker)->postJson($this->url("/tasks/{$task['id']}/submit"), $point + ['quantity' => 1.8, 'unit' => 'ha', 'note' => 'Finished the east side'])
            ->assertOk()->assertJsonPath('data.status', 'submitted')->json('data');
        $this->assertSame(120, $submitted['worked_minutes']);   // 60 + 60 minutes

        // Invalid steps are 409s with the current status.
        $this->as($this->fieldWorker)->postJson($this->url("/tasks/{$task['id']}/start"))->assertStatus(409)->assertJsonPath('code', 'invalid_state_transition')->assertJsonPath('status', 'submitted');

        // Verification: the agronomist (crop work) verifies; the task and activity complete.
        $this->as($this->agronomist)->postJson($this->url("/tasks/{$task['id']}/verify"), ['note' => 'Clean job'])->assertOk()->assertJsonPath('data.status', 'verified');
        $this->as($this->manager)->getJson($this->url("/activities/{$activity['id']}"))->assertJsonPath('data.status', 'completed');

        // The crop lot's history shows the work, with the worker, place and quantity (no names or money).
        $event = $this->inFarm($this->farm, fn () => TraceEvent::where('batch_id', $cycle['crop_lot']['id'] ?? $cycle['crop_lot_batch_id'] ?? null)->where('event_type', 'work_done')->first());
        $this->assertNotNull($event);
        $this->assertSame($worker['id'], $event->worker_id);
        $this->assertEquals(['activity' => 'ACT-001', 'activity_type' => 'weeding', 'quantity' => '1.800', 'task' => 'TSK-001', 'unit' => 'ha', 'worked_minutes' => 120, 'worker' => 'WRK-001'], $event->payload);
        $this->assertEqualsWithDelta(0.3476, (float) $event->latitude, 0.0001);

        // The task's log: every step, with the place for the worker and supervisors.
        $shown = $this->as($this->manager)->getJson($this->url("/tasks/{$task['id']}"))->json('data');
        $this->assertSame(['start', 'pause', 'resume', 'submit', 'verify'], array_column($shown['logs'], 'event'));
        $this->assertSame(0.3476, $shown['logs'][0]['point']['lat']);
    }

    public function test_reject_rework_and_cancel(): void
    {
        $worker = $this->worker($this->fieldWorker);
        $activity = $this->activity($this->manager, ['activity_type_id' => $this->type('fencing'), 'title' => 'Fix the paddock fence', 'worker_ids' => [$worker['id']]]);
        $this->assertSame('general', $activity['subject']['type']);
        $task = $activity['tasks'][0]['id'];

        $this->as($this->fieldWorker)->postJson($this->url("/tasks/{$task}/start"))->assertOk();
        $this->as($this->fieldWorker)->postJson($this->url("/tasks/{$task}/submit"))->assertOk();
        $this->as($this->manager)->postJson($this->url("/tasks/{$task}/reject"), [])->assertStatus(422);
        $this->as($this->manager)->postJson($this->url("/tasks/{$task}/reject"), ['reason' => 'Two posts still loose'])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.review_note', 'Two posts still loose');
        $this->as($this->fieldWorker)->postJson($this->url("/tasks/{$task}/start"))->assertOk()->assertJsonPath('data.status', 'in_progress');

        // Nobody but the manager cancels; the activity follows its tasks.
        $this->as($this->fieldWorker)->postJson($this->url("/tasks/{$task}/cancel"), ['reason' => 'nope'])->assertForbidden();
        $this->as($this->manager)->postJson($this->url("/activities/{$activity['id']}/cancel"), ['reason' => 'Contractor will do it'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->as($this->fieldWorker)->postJson($this->url("/tasks/{$task}/submit"))->assertStatus(409);
    }

    public function test_workers_see_only_their_own_tasks_and_no_money(): void
    {
        $mine = $this->worker($this->fieldWorker);
        $theirs = $this->worker($this->otherWorker);
        $a = $this->activity($this->manager, ['activity_type_id' => $this->type('general_labour'), 'worker_ids' => [$mine['id'], $theirs['id']]]);
        $theirTask = collect($a['tasks'])->firstWhere('worker.id', $theirs['id'])['id'];

        $this->as($this->fieldWorker)->getJson($this->url('/tasks'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.worker.id', $mine['id']);
        $this->as($this->fieldWorker)->getJson($this->url("/tasks/{$theirTask}"))->assertNotFound();
        $this->as($this->fieldWorker)->postJson($this->url("/tasks/{$theirTask}/start"))->assertNotFound();
        $this->as($this->fieldWorker)->getJson($this->url("/activities/{$a['id']}"))->assertOk()->assertJsonCount(2, 'data.tasks');

        // Workers: a field worker sees only their own profile, never a rate.
        $this->as($this->owner)->patchJson($this->url("/workers/{$mine['id']}"), ['daily_rate' => 15000])->assertOk()->assertJsonPath('data.daily_rate', 15000);
        $this->as($this->manager)->patchJson($this->url("/workers/{$mine['id']}"), ['daily_rate' => 20000])->assertForbidden()->assertJsonPath('code', 'money_field_forbidden');
        $this->as($this->manager)->getJson($this->url("/workers/{$mine['id']}"))->assertJsonMissingPath('data.daily_rate');
        $this->as($this->fieldWorker)->getJson($this->url('/workers'))->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.daily_rate');
        $this->as($this->fieldWorker)->getJson($this->url("/workers/{$theirs['id']}"))->assertNotFound();
        $this->as($this->fieldWorker)->postJson($this->url('/workers'), ['full_name' => 'Me Again', 'employment_type' => 'casual'])->assertForbidden();
        $this->as($this->fieldWorker)->getJson($this->url('/dashboards/owner'))->assertForbidden();

        // A member is linked to one worker profile only.
        $this->as($this->manager)->postJson($this->url('/workers'), ['full_name' => 'Duplicate', 'employment_type' => 'casual', 'farm_user_id' => $this->membership($this->fieldWorker)])
            ->assertStatus(409)->assertJsonPath('code', 'duplicate');
    }

    public function test_module_rules_and_four_eyes(): void
    {
        $worker = $this->worker($this->fieldWorker);
        $cycle = $this->cycle();

        // The livestock manager plans animal work, not crop work; the agronomist the reverse.
        $this->as($this->keeper)->postJson($this->url('/activities'), ['activity_type_id' => $this->type('weeding'), 'subject_type' => 'crop_cycle', 'subject_id' => $cycle['id'], 'worker_ids' => [$worker['id']]])
            ->assertForbidden()->assertJsonPath('code', 'module_forbidden');
        $this->as($this->agronomist)->postJson($this->url('/activities'), ['activity_type_id' => $this->type('milking'), 'worker_ids' => [$worker['id']]])
            ->assertForbidden()->assertJsonPath('code', 'module_forbidden');
        // Milking is not done on a crop cycle.
        $this->as($this->manager)->postJson($this->url('/activities'), ['activity_type_id' => $this->type('milking'), 'subject_type' => 'crop_cycle', 'subject_id' => $cycle['id'], 'worker_ids' => [$worker['id']]])
            ->assertStatus(422);

        $crop = $this->activity($this->agronomist, ['activity_type_id' => $this->type('scouting'), 'subject_type' => 'crop_cycle', 'subject_id' => $cycle['id'], 'worker_ids' => [$worker['id']]]);
        $this->as($this->keeper)->getJson($this->url('/tasks'))->assertJsonCount(0, 'data');
        $this->as($this->keeper)->getJson($this->url("/activities/{$crop['id']}"))->assertNotFound();
        $this->as($this->keeper)->postJson($this->url("/tasks/{$crop['tasks'][0]['id']}/verify"))->assertNotFound();

        // A supervisor who is also the worker cannot verify their own work.
        $mgrWorker = $this->worker($this->manager, ['full_name' => 'Manager Self']);
        $own = $this->activity($this->manager, ['activity_type_id' => $this->type('general_labour'), 'worker_ids' => [$mgrWorker['id']]])['tasks'][0]['id'];
        $this->memberGrants($this->manager, ['tasks.execute' => 'assigned']);
        $this->as($this->manager)->postJson($this->url("/tasks/{$own}/start"))->assertOk();
        $this->as($this->manager)->postJson($this->url("/tasks/{$own}/submit"))->assertOk();
        $this->as($this->manager)->postJson($this->url("/tasks/{$own}/verify"))->assertForbidden()->assertJsonPath('code', 'four_eyes');
        $this->as($this->owner)->postJson($this->url("/tasks/{$own}/verify"))->assertOk();
    }

    public function test_assigned_scope_lets_field_workers_record_on_their_crop_cycle(): void
    {
        $worker = $this->worker($this->fieldWorker);
        $mine = $this->cycle();
        $other = $this->cycle();
        $op = fn (array $cycle) => $this->as($this->fieldWorker)->postJson($this->url('/crop-operations'), ['cycle_id' => $cycle['id'], 'type' => 'weeding', 'labour_hours' => 4]);

        $op($mine)->assertForbidden()->assertJsonPath('code', 'not_assigned');
        $task = $this->activity($this->agronomist, ['activity_type_id' => $this->type('weeding'), 'subject_type' => 'crop_cycle', 'subject_id' => $mine['id'], 'worker_ids' => [$worker['id']]])['tasks'][0]['id'];

        $op($mine)->assertCreated()->assertJsonPath('data.status', 'recorded');   // waits for verification
        $op($other)->assertForbidden();

        // Once the task is submitted, the assignment is over.
        $this->as($this->fieldWorker)->postJson($this->url("/tasks/{$task}/start"))->assertOk();
        $this->as($this->fieldWorker)->postJson($this->url("/tasks/{$task}/submit"))->assertOk();
        $op($mine)->assertForbidden();
    }

    public function test_attendance_gps_and_manual_corrections(): void
    {
        $worker = $this->worker($this->fieldWorker);
        $this->as($this->manager)->postJson($this->url('/attendance/check-in'))->assertForbidden();   // no attendance.record

        $in = $this->as($this->fieldWorker)->postJson($this->url('/attendance/check-in'), ['lat' => 0.35, 'lng' => 32.58, 'accuracy_m' => 12, 'occurred_at' => now()->subHours(3)->toIso8601String()])
            ->assertCreated()->assertJsonPath('data.source', 'web')->json('data');
        $this->as($this->fieldWorker)->postJson($this->url('/attendance/check-in'))->assertStatus(409)->assertJsonPath('code', 'already_checked_in');
        $this->as($this->fieldWorker)->postJson($this->url('/attendance/check-in'), ['occurred_at' => now()->addHour()->toIso8601String()])->assertStatus(422);

        // GPS is only kept during the work session.
        $this->as($this->fieldWorker)->postJson($this->url('/gps-points'), ['points' => [
            ['recorded_at' => now()->subHours(2)->toIso8601String(), 'lat' => 0.351, 'lng' => 32.581],
            ['recorded_at' => now()->subHours(5)->toIso8601String(), 'lat' => 0.352, 'lng' => 32.582],
        ]])->assertOk()->assertJsonPath('data', ['accepted' => 1, 'skipped' => 1]);

        $out = $this->as($this->fieldWorker)->postJson($this->url('/attendance/check-out'))->assertOk()->json('data');
        $this->assertEqualsWithDelta(180, $out['minutes'], 1);
        $this->as($this->fieldWorker)->postJson($this->url('/attendance/check-out'))->assertStatus(409)->assertJsonPath('code', 'not_checked_in');

        // Supervisors see the place and the track; workers see only their own days.
        $this->as($this->manager)->getJson($this->url('/attendance'))->assertJsonPath('data.0.check_in_point.lat', 0.35);
        $this->as($this->manager)->getJson($this->url("/workers/{$worker['id']}/track?date=".now($this->farm->timezone)->toDateString()))->assertJsonCount(1, 'data');
        $this->as($this->fieldWorker)->getJson($this->url("/workers/{$worker['id']}/track?date=".now()->toDateString()))->assertForbidden();

        // A manager enters a day for a casual worker without a phone, and corrects times with a reason.
        $casual = $this->worker(null, ['full_name' => 'Okello Casual']);
        $entry = $this->as($this->manager)->postJson($this->url('/attendance'), [
            'worker_id' => $casual['id'], 'check_in_at' => now()->subDay()->setTime(7, 0)->toIso8601String(), 'check_out_at' => now()->subDay()->setTime(16, 0)->toIso8601String(), 'note' => 'Signed the paper register',
        ])->assertCreated()->assertJsonPath('data.source', 'manual')->assertJsonPath('data.minutes', 540)->json('data');
        $this->as($this->manager)->patchJson($this->url("/attendance/{$entry['id']}"), ['check_out_at' => now()->subDay()->setTime(17, 0)->toIso8601String(), 'note' => 'Stayed late for milking', 'version' => $entry['version']])
            ->assertOk()->assertJsonPath('data.minutes', 600);
        $this->as($this->fieldWorker)->postJson($this->url('/attendance'), ['worker_id' => $casual['id'], 'check_in_at' => now()->toIso8601String(), 'note' => 'x'])->assertForbidden();
        $this->as($this->fieldWorker)->getJson($this->url('/attendance'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $in['id']);
        $this->assertTrue($this->inFarm($this->farm, fn () => DB::table('audit_logs')->where('action', 'workforce.attendance.corrected')->exists()));
    }

    public function test_leave_requests(): void
    {
        $worker = $this->worker($this->fieldWorker);
        $from = now()->addDays(3)->toDateString();
        $to = now()->addDays(5)->toDateString();

        $leave = $this->as($this->fieldWorker)->postJson($this->url('/leave'), ['kind' => 'sick', 'from_on' => $from, 'to_on' => $to, 'reason' => 'Clinic'])
            ->assertCreated()->assertJsonPath('data.days', 3)->assertJsonPath('data.status', 'requested')->json('data');
        $this->as($this->fieldWorker)->postJson($this->url('/leave'), ['kind' => 'annual', 'from_on' => $to, 'to_on' => now()->addDays(8)->toDateString()])->assertStatus(409)->assertJsonPath('code', 'overlapping_leave');
        $this->as($this->fieldWorker)->postJson($this->url("/leave/{$leave['id']}/approve"))->assertForbidden();
        $this->as($this->manager)->postJson($this->url("/leave/{$leave['id']}/approve"), ['note' => 'Get well'])->assertOk()->assertJsonPath('data.status', 'approved');

        // Work cannot be assigned during approved leave.
        $this->as($this->manager)->postJson($this->url('/activities'), ['activity_type_id' => $this->type('general_labour'), 'planned_on' => now()->addDays(4)->toDateString(), 'worker_ids' => [$worker['id']]])
            ->assertStatus(409)->assertJsonPath('code', 'worker_on_leave');

        // Leave lists: workers see their own, approvers all.
        $this->worker($this->otherWorker);
        $this->as($this->otherWorker)->postJson($this->url('/leave'), ['kind' => 'annual', 'from_on' => $from, 'to_on' => $from])->assertCreated();
        $this->as($this->fieldWorker)->getJson($this->url('/leave'))->assertJsonCount(1, 'data');
        $this->as($this->manager)->getJson($this->url('/leave'))->assertJsonCount(2, 'data');
        $this->as($this->fieldWorker)->postJson($this->url("/leave/{$leave['id']}/cancel"))->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_manager_and_worker_dashboards(): void
    {
        $worker = $this->worker($this->fieldWorker);
        $other = $this->worker($this->otherWorker);
        $cycle = $this->cycle();
        $crop = $this->activity($this->agronomist, ['activity_type_id' => $this->type('weeding'), 'subject_type' => 'crop_cycle', 'subject_id' => $cycle['id'], 'worker_ids' => [$worker['id']]]);
        $this->activity($this->manager, ['activity_type_id' => $this->type('fencing'), 'planned_on' => now()->subDays(3)->toDateString(), 'due_on' => now()->subDay()->toDateString(), 'worker_ids' => [$other['id']]]);
        $task = $crop['tasks'][0]['id'];
        $this->as($this->fieldWorker)->postJson($this->url('/attendance/check-in'))->assertCreated();
        $this->as($this->fieldWorker)->postJson($this->url("/tasks/{$task}/start"))->assertOk();
        $this->as($this->fieldWorker)->postJson($this->url("/tasks/{$task}/submit"), ['quantity' => 1.5, 'unit' => 'ha'])->assertOk();
        $this->as($this->otherWorker)->postJson($this->url('/leave'), ['kind' => 'annual', 'from_on' => now()->addWeek()->toDateString(), 'to_on' => now()->addWeek()->toDateString()])->assertCreated();

        $data = $this->as($this->manager)->getJson($this->url('/dashboards/manager'))->assertOk()->json('data');
        $kpis = collect($data['kpis'])->pluck('value', 'key');
        $this->assertSame(1, $kpis['tasks.today']);
        $this->assertSame(1, $kpis['tasks.pending']);
        $this->assertSame(1, $kpis['tasks.overdue']);
        $this->assertSame(1, $kpis['workers.present']);
        $this->assertSame(1, $kpis['workers.absent']);
        $this->assertSame(2, $kpis['activities.active']);
        $widgets = collect($data['widgets'])->keyBy('key');
        $this->assertSame("/farms/{$this->farm->id}/tasks/{$task}", $widgets['verification_queue']['data']['items'][0]['href']);
        $this->assertStringContainsString('1.5 ha', $widgets['verification_queue']['data']['items'][0]['subtitle']);
        $this->assertCount(1, $widgets['overdue_tasks']['data']['items']);
        $this->assertCount(1, $widgets['leave_requests']['data']['items']);
        $this->assertContains('new_task', array_column($data['quick_actions'], 'key'));
        $this->as($this->manager)->getJson($this->url('/dashboards/manager/widgets/worker_activity'))->assertOk()->assertJsonPath('data.series.0.key', 'verified');

        // The livestock manager's queue has no crop work.
        $queue = collect($this->as($this->keeper)->getJson($this->url('/dashboards/livestock'))->json('data.widgets'))->firstWhere('key', 'verification_queue');
        $this->assertSame([], $queue['data']['items']);

        // The field worker's own dashboard, not cached across workers.
        $mine = $this->as($this->fieldWorker)->getJson($this->url('/dashboards/worker'))->assertOk()->json('data');
        $this->assertSame(['tasks.today', 'tasks.done_today', 'attendance.status'], array_column($mine['kpis'], 'key'));
        $this->assertSame(1, $mine['kpis'][1]['value']);
        $this->assertStringStartsWith('Checked in at', $mine['kpis'][2]['value']);
        $week = $this->as($this->fieldWorker)->getJson($this->url('/dashboards/worker/widgets/attendance_week'))->assertOk()->json('data');
        $this->assertCount(7, $week['x']['values']);
        $theirs = $this->as($this->otherWorker)->getJson($this->url('/dashboards/worker'))->json('data');
        $this->assertSame('Not checked in', $theirs['kpis'][2]['value']);
        $this->assertSame('Fencing', collect($theirs['widgets'])->firstWhere('key', 'today_tasks')['data']['items'][0]['title']);
    }

    public function test_task_logs_are_append_only(): void
    {
        $worker = $this->worker($this->fieldWorker);
        $task = $this->activity($this->manager, ['activity_type_id' => $this->type('general_labour'), 'worker_ids' => [$worker['id']]])['tasks'][0]['id'];
        $this->as($this->fieldWorker)->postJson($this->url("/tasks/{$task}/start"))->assertOk();

        $log = $this->inFarm($this->farm, fn () => TaskLog::where('task_id', $task)->firstOrFail());
        $this->assertThrows(fn () => $this->inFarm($this->farm, fn () => $log->forceFill(['note' => 'x'])->save()), AppendOnlyViolation::class);
        $this->expectException(QueryException::class);
        $this->inFarm($this->farm, fn () => DB::table('worker_task_logs')->where('id', $log->id)->update(['note' => 'tampered']));
    }

    /** Give a member extra grants on a new custom role. */
    private function memberGrants(User $user, array $grants): void
    {
        $this->inFarm($this->farm, function () use ($user, $grants) {
            $roleId = (string) Str::uuid7();
            DB::table('farm_roles')->insert(['id' => $roleId, 'farm_id' => $this->farm->id, 'key' => 'extra_'.substr($roleId, -6), 'name' => 'Extra', 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($grants as $perm => $scope) {
                DB::table('farm_role_permissions')->insert(['farm_id' => $this->farm->id, 'farm_role_id' => $roleId, 'permission_id' => DB::table('permissions')->where('key', $perm)->value('id'), 'scope' => $scope]);
            }
            DB::table('farm_user_roles')->insert(['farm_id' => $this->farm->id, 'farm_user_id' => $this->membership($user), 'farm_role_id' => $roleId, 'created_at' => now()]);
        });
    }
}
