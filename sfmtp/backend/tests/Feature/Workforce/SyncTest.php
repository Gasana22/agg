<?php

namespace Tests\Feature\Workforce;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Workforce\Domain\Models\TaskLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The mobile offline scenario (docs/08 §6) against the sync API. */
class SyncTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Farm $farm;

    private User $manager;

    private User $fieldWorker;

    private array $worker;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['sfmtp.sync.pull_lag_seconds' => 0]);
        $this->seed(CatalogSeeder::class);
        $this->farm = $this->farm();
        $this->manager = $this->memberWithRole($this->farm, 'manager');
        $this->fieldWorker = $this->memberWithRole($this->farm, 'field_worker');
        $this->worker = $this->asUser($this->manager)->postJson($this->url('/workers'), [
            'full_name' => 'Amina Field', 'employment_type' => 'permanent',
            'farm_user_id' => FarmUser::where('farm_id', $this->farm->id)->where('user_id', $this->fieldWorker->id)->value('id'),
        ])->assertCreated()->json('data');
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}{$path}";
    }

    private function task(string $title): array
    {
        return $this->asUser($this->manager)->postJson($this->url('/activities'), [
            'activity_type_id' => DB::table('global_activity_types')->where('code', 'general_labour')->value('id'),
            'title' => $title, 'worker_ids' => [$this->worker['id']],
        ])->assertCreated()->json('data.tasks.0');
    }

    private function mutation(string $entity, string $op, array $data, array $extra = []): array
    {
        return $extra + ['mutation_id' => (string) Str::uuid7(), 'entity' => $entity, 'op' => $op, 'id' => (string) Str::uuid7(), 'occurred_at' => now()->toIso8601String(), 'data' => $data];
    }

    private function push(array $mutations, ?User $as = null): array
    {
        return $this->asUser($as ?? $this->fieldWorker, 'mobile')->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson($this->url('/sync/push'), ['mutations' => $mutations])->assertOk()->json('data.results');
    }

    private function pull(?string $cursor = null): array
    {
        return $this->asUser($this->fieldWorker, 'mobile')->getJson($this->url('/sync/pull'.($cursor !== null ? "?cursor={$cursor}" : '')))->assertOk()->json('data');
    }

    public function test_airplane_mode_day_syncs_once_with_device_times(): void
    {
        $t1 = $this->task('Clear the drainage ditch');
        $t2 = $this->task('Stack firewood');

        // First sync: a snapshot of what the phone mirrors.
        $snapshot = $this->pull();
        $this->assertEqualsCanonicalizing([$t1['id'], $t2['id']], array_column(array_filter($snapshot['changes'], fn ($c) => $c['entity'] === 'tasks'), 'id'));
        $this->assertSame([$this->worker['id']], array_column(array_filter($snapshot['changes'], fn ($c) => $c['entity'] === 'workers'), 'id'));
        $this->assertArrayNotHasKey('daily_rate', collect($snapshot['changes'])->firstWhere('entity', 'workers')['data']);

        // Offline: check in, start, photo, submit, check out, each with the phone's time and place.
        $png = base64_decode(self::PNG);
        $mediaId = null;
        $at = fn (int $minutesAgo) => now()->subMinutes($minutesAgo)->toIso8601String();
        $queue = [
            $in = $this->mutation('worker_attendance', 'check_in', ['lat' => 0.35, 'lng' => 32.58, 'accuracy_m' => 9], ['occurred_at' => $at(240)]),
            $start = $this->mutation('worker_task_logs', 'insert', ['task_id' => $t1['id'], 'event' => 'start', 'lat' => 0.351, 'lng' => 32.581], ['occurred_at' => $at(200)]),
            $photo = $this->mutation('worker_task_photos', 'insert', ['task_id' => $t1['id'], 'media_id' => $pendingMedia = (string) Str::uuid7(), 'caption' => 'Ditch cleared'], ['occurred_at' => $at(125)]),
            $submit = $this->mutation('worker_task_logs', 'insert', ['task_id' => $t1['id'], 'event' => 'submit', 'quantity' => 40, 'unit' => 'm', 'note' => '40 metres'], ['occurred_at' => $at(120)]),
            $gps = $this->mutation('worker_gps_points', 'insert', ['points' => [['recorded_at' => $at(180), 'lat' => 0.352, 'lng' => 32.582, 'accuracy_m' => 5]]], ['id' => null]),
            $out = $this->mutation('worker_attendance', 'check_out', [], ['occurred_at' => $at(30), 'id' => null]),
        ];
        $results = collect($this->push($queue))->keyBy('mutation_id');
        $this->assertSame('applied', $results[$in['mutation_id']]['status']);
        $this->assertSame('applied', $results[$start['mutation_id']]['status']);
        $this->assertSame('deferred', $results[$photo['mutation_id']]['status']);   // not uploaded yet
        $this->assertSame('applied', $results[$submit['mutation_id']]['status']);
        $this->assertSame('submitted', $results[$submit['mutation_id']]['server']['data']['status']);
        $this->assertSame(80, $results[$submit['mutation_id']]['server']['data']['worked_minutes']);
        $this->assertSame(['accepted' => 1, 'skipped' => 0], $results[$gps['mutation_id']]['server']['result'] ?? $results[$gps['mutation_id']]['result'] ?? null);
        $this->assertSame('applied', $results[$out['mutation_id']]['status']);

        // The photo uploads (checksummed, deduplicated), then its mutation goes through.
        $file = UploadedFile::fake()->createWithContent('ditch.png', $png);
        $upload = $this->asUser($this->fieldWorker, 'mobile')->withHeader('Idempotency-Key', (string) Str::uuid7())->post($this->url('/media/uploads'), ['file' => $file, 'sha256' => hash('sha256', $png)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
        $this->asUser($this->fieldWorker, 'mobile')->withHeader('Idempotency-Key', (string) Str::uuid7())->post($this->url('/media/uploads'), ['file' => UploadedFile::fake()->createWithContent('again.png', $png)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.id', $upload['id']);
        $this->asUser($this->fieldWorker, 'mobile')->withHeader('Idempotency-Key', (string) Str::uuid7())->post($this->url('/media/uploads'), ['file' => UploadedFile::fake()->createWithContent('bad.png', $png), 'sha256' => str_repeat('a', 64)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'checksum_mismatch');
        $photo['data']['media_id'] = $upload['id'];
        $this->assertSame('applied', $this->push([$photo])[0]['status']);

        // A retried push (lost response) changes nothing.
        $again = collect($this->push($queue))->keyBy('mutation_id');
        $this->assertSame('duplicate', $again[$start['mutation_id']]['status']);
        $this->assertSame('applied', $again[$start['mutation_id']]['original_status']);
        $logs = $this->inFarm($this->farm, fn () => TaskLog::where('task_id', $t1['id'])->orderBy('occurred_at')->get());
        $this->assertSame(['start', 'submit'], $logs->pluck('event.value')->all());
        $this->assertSame($start['id'], $logs[0]->id);   // the phone's id
        $this->assertEqualsWithDelta(now()->subMinutes(200)->timestamp, $logs[0]->occurred_at->timestamp, 2);

        // The supervisor sees the photo and can open it; others cannot.
        $shown = $this->asUser($this->manager)->getJson($this->url("/tasks/{$t1['id']}"))->assertJsonCount(1, 'data.photos')->json('data');
        $this->assertSame($upload['id'], $shown['photos'][0]['media_id']);
        $this->asUser($this->manager)->get($this->url("/media/{$upload['id']}/content"))->assertOk()->assertHeader('Content-Type', 'image/png');
        $other = $this->memberWithRole($this->farm, 'store_manager');
        $this->asUser($other)->getJson($this->url("/media/{$upload['id']}"))->assertNotFound();

        // Pull from the snapshot cursor: the changed task and attendance, current state.
        $changes = collect($this->pull($snapshot['next_cursor'])['changes']);
        $this->assertSame('submitted', $changes->firstWhere('id', $t1['id'])['data']['status']);
        $this->assertNotNull($changes->firstWhere('entity', 'attendance')['data']['check_out_at']);
    }

    public function test_conflicts_rejections_and_removals(): void
    {
        $task = $this->task('Mend the gate');
        $cursor = $this->pull()['next_cursor'];

        // The manager cancels while the phone is offline; the phone's start becomes a conflict.
        $this->asUser($this->manager)->postJson($this->url("/tasks/{$task['id']}/cancel"), ['reason' => 'Bought a new gate'])->assertOk();
        $start = $this->mutation('worker_task_logs', 'insert', ['task_id' => $task['id'], 'event' => 'start']);
        $result = $this->push([$start])[0];
        $this->assertSame('conflict', $result['status']);
        $this->assertSame('invalid_state_transition', $result['error']['code']);
        $this->assertSame('cancelled', $result['server']['data']['status']);
        // The refused step is kept as evidence.
        $log = $this->inFarm($this->farm, fn () => TaskLog::find($start['id']));
        $this->assertFalse($log->applied);
        $this->assertSame('duplicate', $this->push([$start])[0]['status']);

        // Rejections: other people's tasks, bad data, the wrong role, unknown entities.
        $theirs = $this->memberWithRole($this->farm, 'field_worker');
        $this->assertSame('no_worker_profile', $this->push([$this->mutation('worker_task_logs', 'insert', ['task_id' => $task['id'], 'event' => 'note', 'note' => 'hi'])], $theirs)[0]['error']['code']);
        $this->asUser($this->manager)->postJson($this->url('/workers'), ['full_name' => 'Other Worker', 'employment_type' => 'casual',
            'farm_user_id' => FarmUser::where('farm_id', $this->farm->id)->where('user_id', $theirs->id)->value('id')])->assertCreated();
        $this->assertSame('not_found', $this->push([$this->mutation('worker_task_logs', 'insert', ['task_id' => $task['id'], 'event' => 'note', 'note' => 'hi'])], $theirs)[0]['error']['code']);
        $bad = $this->push([$this->mutation('worker_task_logs', 'insert', ['task_id' => $task['id'], 'event' => 'verify'])])[0];
        $this->assertSame(['rejected', 'validation_failed'], [$bad['status'], $bad['error']['code']]);
        $future = $this->push([$this->mutation('worker_attendance', 'check_in', [], ['occurred_at' => now()->addHours(2)->toIso8601String()])])[0];
        $this->assertSame('rejected', $future['status']);
        $keeper = $this->memberWithRole($this->farm, 'livestock_manager');
        $this->assertSame('forbidden', $this->push([$this->mutation('worker_task_logs', 'insert', ['task_id' => $task['id'], 'event' => 'start'])], $keeper)[0]['error']['code']);
        $this->assertSame('unsupported', $this->push([$this->mutation('animals', 'insert', [])])[0]['error']['code']);

        // A second check-in the same day is a conflict carrying the server's record.
        $this->assertSame('applied', $this->push([$this->mutation('worker_attendance', 'check_in', [])])[0]['status']);
        $dup = $this->push([$this->mutation('worker_attendance', 'check_in', [])])[0];
        $this->assertSame(['conflict', 'already_checked_in'], [$dup['status'], $dup['error']['code']]);
        $this->assertSame('attendance', $dup['server']['entity']);

        // Unlinking the worker profile removes it from the phone on the next pull.
        $this->asUser($this->manager)->patchJson($this->url("/workers/{$this->worker['id']}"), ['farm_user_id' => null])->assertOk();
        $changes = collect($this->pull($cursor)['changes']);
        $this->assertSame('remove', $changes->firstWhere('entity', 'workers')['op']);
        $this->assertSame('remove', $changes->firstWhere('id', $task['id'])['op']);
    }

    public function test_leave_from_the_phone_and_me_today(): void
    {
        $this->task('Weed the kitchen garden');
        $leave = $this->mutation('worker_leave', 'insert', ['kind' => 'annual', 'from_on' => now()->addWeek()->toDateString(), 'to_on' => now()->addWeek()->addDays(2)->toDateString()]);
        $this->assertSame('applied', $this->push([$leave])[0]['status']);

        $today = $this->asUser($this->fieldWorker, 'mobile')->getJson($this->url('/me/today'))->assertOk()->json('data');
        $this->assertSame($this->worker['id'], $today['worker']['id']);
        $this->assertCount(1, $today['tasks']);
        $this->assertSame(1, $today['counts']['open']);
        $this->assertSame($leave['id'], $today['leave'][0]['id']);
        $this->assertNull($today['attendance']);
    }
}
