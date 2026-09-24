<?php

namespace Tests\Feature\Sync;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\Identity\Application\TokenService;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserDevice;
use App\Modules\Notifications\Contracts\PushSender;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 11 gate (docs/08 §6): the agronomist, livestock and manager flows
 * offline, field-level merge and conflicts, notifications and push, and
 * 1,000 queued mutations.
 */
class MobileSyncTest extends TestCase
{
    private Farm $farm;

    private User $owner;

    private User $agronomist;

    private User $keeper;

    private User $manager;

    private User $fieldWorker;

    private string $cycle;

    private string $cow;

    private string $group;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sfmtp.sync.pull_lag_seconds' => 0]);
        RateLimiter::for('sync', fn () => Limit::none());
        $this->seed(CatalogSeeder::class);
        $this->farm = $this->farm();
        $this->owner = $this->ownerOf($this->farm);
        $this->agronomist = $this->memberWithRole($this->farm, 'agronomist');
        $this->keeper = $this->memberWithRole($this->farm, 'livestock_manager');
        $this->manager = $this->memberWithRole($this->farm, 'manager');
        $this->fieldWorker = $this->memberWithRole($this->farm, 'field_worker');

        $o = $this->asUser($this->owner);
        $plot = $o->postJson($this->url('/structure/plots'), ['name' => 'B-3', 'code' => 'B-3', 'declared_area_ha' => 2])->assertCreated()->json('data.id');
        $crop = $o->postJson($this->url('/crops'), ['global_variety_id' => DB::table('global_crop_varieties')->where('code', 'longe_5')->value('id')])->json('data.id');
        $this->cycle = $o->postJson($this->url('/crop-cycles'), ['plot_id' => $plot, 'crop_id' => $crop, 'planted_on' => now()->subDays(30)->toDateString()])->assertCreated()->json('data.id');
        $cattle = DB::table('global_animal_species')->where('code', 'cattle')->value('id');
        $this->group = $o->postJson($this->url('/animal-groups'), ['name' => 'Milking herd', 'species_id' => $cattle, 'purpose' => 'dairy'])->assertCreated()->json('data.id');
        $this->cow = $o->postJson($this->url('/animals'), ['species_id' => $cattle, 'sex' => 'female', 'origin' => 'purchased', 'name' => 'Bella', 'tag_number' => 'UG-1001', 'group_id' => $this->group])
            ->assertCreated()->json('data.id');
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}{$path}";
    }

    private function m(string $entity, string $op, array $data, array $extra = []): array
    {
        return $extra + ['mutation_id' => (string) Str::uuid7(), 'entity' => $entity, 'op' => $op, 'id' => (string) Str::uuid7(), 'occurred_at' => now()->toIso8601String(), 'data' => $data];
    }

    private function push(User $as, array $mutations): array
    {
        return $this->asUser($as, 'mobile')->postJson($this->url('/sync/push'), ['mutations' => $mutations], ['Idempotency-Key' => (string) Str::uuid7()])
            ->assertOk()->json('data.results');
    }

    private function pull(User $as, ?string $cursor = null): array
    {
        return $this->asUser($as, 'mobile')->getJson($this->url('/sync/pull'.($cursor !== null ? "?cursor={$cursor}" : '')))->assertOk()->json('data');
    }

    /** @return array<string, int> entity => count */
    private function mirror(User $as): array
    {
        return collect($this->pull($as)['changes'])->countBy('entity')->all();
    }

    public function test_each_role_mirrors_what_it_works_with(): void
    {
        $agronomist = $this->mirror($this->agronomist);
        $this->assertSame(1, $agronomist['plots'] ?? 0);
        $this->assertSame(1, $agronomist['crop_cycles'] ?? 0);
        $this->assertArrayNotHasKey('animals', $agronomist);
        $this->assertArrayNotHasKey('tasks', $agronomist);

        $keeper = $this->mirror($this->keeper);
        $this->assertSame(1, $keeper['animals'] ?? 0);
        $this->assertSame(1, $keeper['animal_groups'] ?? 0);
        $this->assertArrayNotHasKey('crop_cycles', $keeper);

        $worker = $this->mirror($this->fieldWorker);
        foreach (['plots', 'crop_cycles', 'animals', 'team_tasks'] as $entity) {
            $this->assertArrayNotHasKey($entity, $worker, "a field worker does not mirror {$entity}");
        }
        // No money reaches a phone.
        $cycle = collect($this->pull($this->agronomist)['changes'])->firstWhere('entity', 'crop_cycles')['data'];
        $this->assertArrayNotHasKey('budget_amount', $cycle);
    }

    public function test_the_agronomist_records_field_work_offline(): void
    {
        $sprayed = now()->subHours(5)->startOfSecond();
        $observation = $this->m('crop_observations', 'insert', ['cycle_id' => $this->cycle, 'kind' => 'pest', 'severity' => 'high', 'title' => 'Fall armyworm', 'affected_pct' => 15, 'latitude' => 0.34, 'longitude' => 32.58],
            ['occurred_at' => now()->subHours(6)->toIso8601String()]);
        $operation = $this->m('crop_operations', 'insert', ['cycle_id' => $this->cycle, 'type' => 'spraying', 'occurred_at' => $sprayed->toIso8601String(),
            'inputs' => [['product_name' => 'Emamectin benzoate', 'quantity' => 0.4, 'unit' => 'kg', 'withholding_days' => 14]]], ['occurred_at' => $sprayed->toIso8601String()]);
        $bad = $this->m('crop_operations', 'insert', ['cycle_id' => $this->cycle, 'type' => 'teleporting']);

        $results = collect($this->push($this->agronomist, [$observation, $operation, $bad]))->keyBy('mutation_id');
        $this->assertSame('applied', $results[$observation['mutation_id']]['status']);
        $this->assertSame('applied', $results[$operation['mutation_id']]['status']);
        $this->assertSame('rejected', $results[$bad['mutation_id']]['status']);
        $this->assertSame('validation_failed', $results[$bad['mutation_id']]['error']['code']);

        // Pushed again (the phone lost the answer): nothing doubles.
        $again = $this->push($this->agronomist, [$observation, $operation]);
        $this->assertSame(['duplicate', 'duplicate'], array_column($again, 'status'));
        $this->inFarm($this->farm, function () use ($sprayed) {
            $this->assertSame(1, DB::table('crop_observations')->count());
            $this->assertSame(1, DB::table('crop_operations')->count());
            // The field time is the phone's, on the record and in the trace.
            $this->assertSame($sprayed->utc()->toDateTimeString(), CarbonImmutable::parse(DB::table('crop_operations')->value('occurred_at'))->utc()->toDateTimeString());
            $this->assertTrue(DB::table('trace_events')->where('event_type', 'input_applied')->exists());
        });

        // A field worker may not.
        $this->assertSame('rejected', $this->push($this->fieldWorker, [$this->m('crop_operations', 'insert', ['cycle_id' => $this->cycle, 'type' => 'weeding'])])[0]['status']);
    }

    public function test_the_livestock_team_records_health_weight_and_milk(): void
    {
        $results = $this->push($this->keeper, [
            $this->m('animal_health', 'insert', ['animal_id' => $this->cow, 'kind' => 'treatment', 'given_on' => now()->toDateString(), 'product_name' => 'Oxytetracycline', 'milk_withdrawal_days' => 4]),
            $this->m('animal_weights', 'insert', ['animal_id' => $this->cow, 'weighed_on' => now()->toDateString(), 'weight_kg' => 412.5]),
            // Under withdrawal: milk must be recorded as discarded.
            $this->m('animal_production', 'insert', ['animal_id' => $this->cow, 'product' => 'milk', 'produced_on' => now()->toDateString(), 'session' => 'am', 'quantity' => 11, 'unit' => 'l']),
            $this->m('animal_production', 'insert', ['animal_id' => $this->cow, 'product' => 'milk', 'produced_on' => now()->toDateString(), 'session' => 'am', 'quantity' => 11, 'unit' => 'l', 'discarded' => true]),
        ]);
        $this->assertSame(['applied', 'applied', 'rejected', 'applied'], array_column($results, 'status'));
        $this->assertSame('withdrawal_period', $results[2]['error']['code']);
        $animal = collect($this->pull($this->keeper)['changes'])->firstWhere('id', $this->cow)['data'];
        $this->assertNotNull($animal['milk_withdrawal_until']);
    }

    public function test_two_devices_edit_the_same_animal(): void
    {
        $seen = collect($this->pull($this->keeper)['changes'])->firstWhere('id', $this->cow);
        $base = ['name' => 'Bella', 'tag_number' => 'UG-1001', 'notes' => null];

        // Phone A (the keeper) renames her and writes a note.
        $a = $this->push($this->keeper, [$this->m('animals', 'update', ['changes' => ['name' => 'Bella II', 'notes' => 'Limps on left hind leg'], 'base' => $base],
            ['id' => $this->cow, 'base_version' => $seen['version']])])[0];
        $this->assertSame('applied', $a['status']);
        $this->assertEqualsCanonicalizing(['name', 'notes'], $a['merged']);

        // Phone B (the owner, from the same earlier copy) changes the tag and a different note.
        $b = $this->push($this->owner, [$this->m('animals', 'update', ['changes' => ['tag_number' => 'UG-2002', 'notes' => 'Due for hoof trimming', 'name' => 'Bella II'], 'base' => $base],
            ['id' => $this->cow, 'base_version' => $seen['version']])])[0];
        $this->assertSame('conflict', $b['status']);
        $this->assertSame(['tag_number'], $b['merged'], 'the tag changed on one side only and merges');
        $this->assertSame('UG-2002', $b['server']['data']['tag_number']);
        $this->assertSame('Bella II', $b['server']['data']['name']);
        $this->assertSame('Limps on left hind leg', $b['server']['data']['notes'], 'the field changed on both sides is not overwritten');

        // B gets the conflict and a notification on the next pull.
        $changes = collect($this->pull($this->owner)['changes']);
        $conflict = $changes->firstWhere('entity', 'conflicts');
        $this->assertSame($b['conflict_id'], $conflict['id']);
        $this->assertSame([['field' => 'notes', 'base' => null, 'mine' => 'Due for hoof trimming', 'server' => 'Limps on left hind leg']], $conflict['data']['fields']);
        $this->assertSame('sync_conflict', $changes->firstWhere('entity', 'notifications')['data']['kind']);
        $cursor = $this->pull($this->owner)['next_cursor'];

        // B keeps their note, offline, through the outbox.
        $resolved = $this->push($this->owner, [$this->m('sync_conflicts', 'resolve', ['conflict_id' => $conflict['id'], 'choices' => ['notes' => 'mine']])])[0];
        $this->assertSame('applied', $resolved['status']);
        $this->asUser($this->owner)->getJson($this->url("/animals/{$this->cow}"))->assertJsonPath('data.notes', 'Due for hoof trimming');
        $after = collect($this->pull($this->owner, $cursor)['changes']);
        $this->assertSame('remove', $after->firstWhere('id', $conflict['id'])['op'], 'a resolved conflict leaves the phone');
        // Only the member whose change it was can resolve, and only once.
        $this->asUser($this->keeper)->postJson($this->url("/sync/conflicts/{$conflict['id']}/resolve"), ['choices' => ['notes' => 'server']])->assertForbidden();
        $this->asUser($this->owner)->postJson($this->url("/sync/conflicts/{$conflict['id']}/resolve"), ['choices' => ['notes' => 'server']])->assertStatus(409);
    }

    public function test_managers_verify_from_the_phone_and_hear_about_refused_steps(): void
    {
        $member = FarmUser::where('farm_id', $this->farm->id)->where('user_id', $this->fieldWorker->id)->value('id');
        $worker = $this->asUser($this->manager)->postJson($this->url('/workers'), ['full_name' => 'Okello', 'employment_type' => 'permanent', 'farm_user_id' => $member])->json('data.id');
        $activity = fn (string $title) => $this->asUser($this->manager)->postJson($this->url('/activities'), [
            'activity_type_id' => DB::table('global_activity_types')->where('code', 'general_labour')->value('id'), 'title' => $title, 'worker_ids' => [$worker],
        ])->assertCreated()->json('data.tasks.0');
        $dig = $activity('Dig the trench');
        $fence = $activity('Mend the fence');
        // The worker was told about each task.
        $this->assertSame(2, collect($this->pull($this->fieldWorker)['changes'])->where('entity', 'notifications')->where('data.kind', 'task_assigned')->count());

        foreach (['start', 'submit'] as $event) {
            $this->push($this->fieldWorker, [$this->m('worker_task_logs', 'insert', ['task_id' => $dig['id'], 'event' => $event])]);
        }
        $queue = collect($this->pull($this->manager)['changes'])->where('entity', 'team_tasks');
        $this->assertSame([$dig['id']], $queue->pluck('id')->values()->all());

        $verify = $this->m('task_reviews', 'verify', ['task_id' => $dig['id'], 'note' => 'Straight and deep']);
        $this->assertSame('applied', $this->push($this->manager, [$verify])[0]['status']);
        $this->assertSame('verified', $this->asUser($this->manager)->getJson($this->url("/tasks/{$dig['id']}"))->json('data.status'));
        // The owner, offline, also tried to reject it: a conflict with the server's state.
        $late = $this->push($this->owner, [$this->m('task_reviews', 'reject', ['task_id' => $dig['id'], 'note' => 'Too shallow'])])[0];
        $this->assertSame('conflict', $late['status']);
        $this->assertSame('verified', $late['server']['data']['status']);

        // The manager cancels the fence task; the worker, offline, starts it anyway.
        $this->asUser($this->manager)->postJson($this->url("/tasks/{$fence['id']}/cancel"), ['reason' => 'Contractor will do it'])->assertOk();
        $step = $this->push($this->fieldWorker, [$this->m('worker_task_logs', 'insert', ['task_id' => $fence['id'], 'event' => 'start'])])[0];
        $this->assertSame('conflict', $step['status']);
        $refused = collect($this->pull($this->manager)['changes'])->where('entity', 'notifications')->firstWhere('data.kind', 'task_step_refused');
        $this->assertNotNull($refused, 'the supervisors are told about the refused step');
        $this->assertSame($fence['id'], $refused['data']['data']['task_id']);
        // No data loss: the refused step is in the task's log.
        $this->inFarm($this->farm, fn () => $this->assertSame(1, DB::table('worker_task_logs')->where('task_id', $fence['id'])->where('applied', false)->count()));
        // And the worker heard about the verified work.
        $this->assertSame(1, collect($this->pull($this->fieldWorker)['changes'])->where('entity', 'notifications')->where('data.kind', 'task_verified')->count());
    }

    public function test_notifications_inbox_and_push(): void
    {
        $sent = [];
        $this->app->instance(PushSender::class, new class($sent) implements PushSender
        {
            public function __construct(private array &$sent) {}

            public function send(string $token, string $title, ?string $body, array $data): bool
            {
                $this->sent[] = compact('token', 'title', 'data');

                return $token !== 'stale-token';
            }
        });
        $device = UserDevice::create(['user_id' => $this->keeper->id, 'client' => 'mobile', 'name' => 'Tecno Spark', 'platform' => 'android']);
        $tokens = $this->app->make(TokenService::class)->issue($this->keeper, 'mobile', $device);
        $this->app['auth']->forgetGuards();
        $this->withToken($tokens->accessToken)->putJson('/api/v1/me/devices/current/push-token', ['token' => 'fcm-token-1', 'platform' => 'fcm'])->assertOk()->assertJsonPath('data.registered', true);

        // A conflict notifies the keeper; the push goes to their phone.
        $seen = collect($this->pull($this->keeper)['changes'])->firstWhere('id', $this->cow);
        $this->asUser($this->owner)->patchJson($this->url("/animals/{$this->cow}"), ['notes' => 'Owner note'], ['If-Match' => (string) $seen['version']])->assertOk();
        $this->push($this->keeper, [$this->m('animals', 'update', ['changes' => ['notes' => 'Keeper note'], 'base' => ['notes' => null]], ['id' => $this->cow, 'base_version' => $seen['version']])]);
        $this->assertCount(1, $sent);
        $this->assertSame('fcm-token-1', $sent[0]['token']);
        $this->assertSame('sync_conflict', $sent[0]['data']['kind']);

        $inbox = $this->asUser($this->keeper)->getJson($this->url('/notifications'))->assertOk()->json();
        $this->assertSame(1, $inbox['meta']['unread']);
        $this->asUser($this->keeper)->postJson($this->url("/notifications/{$inbox['data'][0]['id']}/read"))->assertOk();
        $this->asUser($this->keeper)->getJson($this->url('/notifications?unread=1'))->assertJsonCount(0, 'data');
        // Someone else's notification is not found.
        $this->asUser($this->owner)->postJson($this->url("/notifications/{$inbox['data'][0]['id']}/read"))->assertNotFound();

        // A token the push service no longer knows is forgotten.
        $device->forceFill(['push_token' => 'stale-token'])->save();
        $this->asUser($this->owner)->patchJson($this->url("/animals/{$this->cow}"), ['notes' => 'Owner again'])->assertOk();
        $this->push($this->keeper, [$this->m('animals', 'update', ['changes' => ['notes' => 'Keeper again'], 'base' => ['notes' => 'Keeper note']], ['id' => $this->cow, 'base_version' => 1])]);
        $this->assertNull($device->fresh()->push_token);
    }

    public function test_a_thousand_queued_mutations_sync_in_five_pushes(): void
    {
        $member = FarmUser::where('farm_id', $this->farm->id)->where('user_id', $this->fieldWorker->id)->value('id');
        $this->asUser($this->manager)->postJson($this->url('/workers'), ['full_name' => 'Okello', 'employment_type' => 'permanent', 'farm_user_id' => $member])->assertCreated();
        $this->push($this->fieldWorker, [$this->m('worker_attendance', 'check_in', ['lat' => 0.35, 'lng' => 32.58], ['occurred_at' => now()->subHours(9)->toIso8601String()])]);

        // A long offline day: 800 GPS points and 200 weighings, in phone order.
        $queue = [];
        for ($i = 0; $i < 800; $i++) {
            $queue[] = [$this->fieldWorker, $this->m('worker_gps_points', 'insert', ['points' => [['recorded_at' => now()->subMinutes(480 - $i * 0.5)->toIso8601String(), 'lat' => 0.35 + $i / 1e5, 'lng' => 32.58, 'accuracy_m' => 8]]])];
        }
        for ($i = 0; $i < 200; $i++) {
            $queue[] = [$this->keeper, $this->m('animal_weights', 'insert', ['animal_id' => $this->cow, 'weighed_on' => now()->subDays(200 - $i)->toDateString(), 'weight_kg' => 300 + $i / 2])];
        }

        $start = microtime(true);
        $applied = 0;
        foreach (collect($queue)->groupBy(fn ($q) => $q[0]->id) as $items) {
            foreach ($items->chunk(200) as $batch) {
                $results = $this->push($batch->first()[0], $batch->pluck(1)->values()->all());
                $applied += collect($results)->where('status', 'applied')->count();
            }
        }
        $elapsed = microtime(true) - $start;

        $this->assertSame(1000, $applied);
        // The server's share of the 60-second budget (the phone test adds a 3G link).
        $this->assertLessThan(40, $elapsed, "1,000 mutations took {$elapsed}s on the server");
        $this->inFarm($this->farm, function () {
            $this->assertSame(800, DB::table('worker_gps_points')->count());
            $this->assertSame(200, DB::table('animal_weights')->count());
        });
    }
}
