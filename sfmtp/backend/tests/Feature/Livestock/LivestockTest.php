<?php

namespace Tests\Feature\Livestock;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Livestock\Domain\Models\HealthRecord;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Domain\Models\TraceEvent;
use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LivestockTest extends TestCase
{
    private Farm $farm;

    private User $owner;

    private User $keeper;   // livestock manager

    private string $cattle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->farm = $this->farm();
        $this->owner = $this->ownerOf($this->farm);
        $this->keeper = $this->memberWithRole($this->farm, 'livestock_manager');
        $this->cattle = DB::table('global_animal_species')->where('code', 'cattle')->value('id');
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}{$path}";
    }

    private function as(User $user)
    {
        return $this->asUser($user);
    }

    private function animal(array $data = []): array
    {
        return $this->as($this->keeper)->postJson($this->url('/animals'), $data + [
            'species_id' => $this->cattle, 'sex' => 'female', 'origin' => 'purchased', 'acquired_on' => now()->subYear()->toDateString(),
            'breed_id' => DB::table('global_animal_breeds')->where('code', 'friesian')->value('id'),
        ])->assertCreated()->json('data');
    }

    /** @return array<int,string> */
    private function events(string $batchId): array
    {
        return $this->inFarm($this->farm, fn () => TraceEvent::where('batch_id', $batchId)->orderBy('farm_seq')->pluck('event_type')->all());
    }

    public function test_register_breed_and_calve_with_lineage_in_traceability(): void
    {
        $cow = $this->animal(['name' => 'Bella', 'tag_number' => 'UG-1001']);
        $bull = $this->animal(['name' => 'Kato', 'sex' => 'male', 'tag_number' => 'UG-2001']);
        $this->assertSame('CAT-001', $cow['animal_code']);
        $this->assertSame(['created', 'registered'], $this->events($cow['batch']['id']));

        $this->as($this->keeper)->postJson($this->url('/animals'), ['species_id' => $this->cattle, 'sex' => 'female', 'origin' => 'purchased', 'tag_number' => 'UG-1001'])
            ->assertStatus(422)->assertJsonValidationErrors('tag_number');

        $served = now()->subDays(290)->toDateString();
        $breeding = $this->as($this->keeper)->postJson($this->url('/animal-breedings'), ['dam_id' => $cow['id'], 'sire_id' => $bull['id'], 'method' => 'natural', 'served_on' => $served])
            ->assertCreated()
            ->assertJsonPath('data.status', 'served')
            ->assertJsonPath('data.expected_due_on', now()->subDays(290)->addDays(283)->toDateString())
            ->json('data');
        $this->assertProblem($this->as($this->keeper)->postJson($this->url('/animal-breedings'), ['dam_id' => $cow['id'], 'method' => 'ai', 'served_on' => $served]), 409, 'breeding_open');
        $this->as($this->keeper)->postJson($this->url('/animal-breedings'), ['dam_id' => $bull['id'], 'method' => 'ai', 'served_on' => $served])
            ->assertStatus(422)->assertJsonValidationErrors('dam_id');

        $this->as($this->keeper)->patchJson($this->url("/animal-breedings/{$breeding['id']}"), ['status' => 'pregnant'])->assertOk()->assertJsonPath('data.status', 'pregnant');
        $this->as($this->keeper)->getJson($this->url('/animals?filter[pregnant]=1'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $cow['id']);

        $born = $this->as($this->keeper)->postJson($this->url("/animal-breedings/{$breeding['id']}/birth"), [
            'born_on' => now()->subDays(5)->toDateString(), 'offspring' => [['sex' => 'female', 'name' => 'Bella II', 'tag_number' => 'UG-1002']],
        ])->assertCreated()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.offspring_count', 1)->json('meta.offspring.0');

        $this->assertSame('born', $born['origin']);
        $this->assertSame($cow['id'], $born['dam']['id']);
        $this->assertSame($bull['id'], $born['sire']['id']);
        $parents = $this->inFarm($this->farm, fn () => DB::table('trace_batch_links')->where('child_batch_id', $born['batch']['id'])->pluck('parent_batch_id')->sort()->values()->all());
        $this->assertEqualsCanonicalizing([$cow['batch']['id'], $bull['batch']['id']], $parents);
        $this->assertContains('gave_birth', $this->events($cow['batch']['id']));
        $this->assertContains('born', $this->events($born['batch']['id']));
    }

    public function test_withdrawal_periods_protect_milk_and_meat(): void
    {
        $cow = $this->animal();
        $given = now()->subDay()->toDateString();

        $treatment = $this->as($this->keeper)->postJson($this->url('/animal-health'), [
            'animal_id' => $cow['id'], 'kind' => 'treatment', 'given_on' => $given, 'diagnosis' => 'Mastitis',
            'product_name' => 'Oxytetracycline 20% LA', 'dose' => 20, 'dose_unit' => 'ml', 'meat_withdrawal_days' => 28, 'milk_withdrawal_days' => 7,
        ])->assertCreated()->json('data');
        $this->assertContains('treated', $this->events($cow['batch']['id']));

        $shown = $this->as($this->keeper)->getJson($this->url("/animals/{$cow['id']}"))->json('data');
        $this->assertSame(now()->subDay()->addDays(7)->toDateString(), $shown['milk_withdrawal_until']);
        $this->assertSame(now()->subDay()->addDays(28)->toDateString(), $shown['meat_withdrawal_until']);

        // Milk under withdrawal must be discarded, and never joins the day's lot.
        $milk = ['animal_id' => $cow['id'], 'product' => 'milk', 'produced_on' => now()->toDateString(), 'session' => 'am', 'quantity' => 12, 'unit' => 'l'];
        $this->assertProblem($this->as($this->keeper)->postJson($this->url('/animal-production'), $milk), 422, 'withdrawal_period');
        $this->as($this->keeper)->postJson($this->url('/animal-production'), $milk + ['discarded' => true])
            ->assertCreated()->assertJsonPath('data.discarded', true)->assertJsonPath('data.lot', null);
        // Milk from before the treatment is fine, even when recorded late.
        $this->as($this->keeper)->postJson($this->url('/animal-production'), ['produced_on' => now()->subDays(3)->toDateString()] + $milk)->assertCreated()->assertJsonPath('data.discarded', false);

        // Voiding the treatment (entered in error) lifts the withdrawal.
        $this->as($this->keeper)->postJson($this->url("/animal-health/{$treatment['id']}/void"), ['reason' => 'Recorded on the wrong cow'])
            ->assertOk()->assertJsonPath('data.voided.reason', 'Recorded on the wrong cow');
        $this->as($this->keeper)->getJson($this->url("/animals/{$cow['id']}"))
            ->assertJsonPath('data.milk_withdrawal_until', null)->assertJsonPath('data.meat_withdrawal_until', null);
        $this->as($this->keeper)->postJson($this->url('/animal-production'), $milk)->assertCreated();
        $this->assertProblem($this->as($this->keeper)->postJson($this->url("/animal-health/{$treatment['id']}/void"), ['reason' => 'again']), 409, 'already_voided');
        $this->as($this->keeper)->getJson($this->url('/animal-health'))->assertJsonCount(0, 'data');
        $this->as($this->keeper)->getJson($this->url('/animal-health?include_voided=1'))->assertJsonCount(1, 'data');
    }

    public function test_daily_milk_lot_is_derived_from_the_cows_that_gave_it(): void
    {
        $a = $this->animal();
        $b = $this->animal();
        $today = now()->toDateString();
        foreach ([[$a, 'am', 8.5], [$a, 'pm', 6.5], [$b, 'am', 10]] as [$cow, $session, $litres]) {
            $this->as($this->keeper)->postJson($this->url('/animal-production'), ['animal_id' => $cow['id'], 'product' => 'milk', 'produced_on' => $today, 'session' => $session, 'quantity' => $litres, 'unit' => 'l'])->assertCreated();
        }

        $lot = $this->inFarm($this->farm, fn () => TraceBatch::where('kind', 'animal_product')->firstOrFail());
        $this->assertSame('25.000', $lot->quantity);
        $this->assertSame('Milk '.$today, $lot->name);

        $journey = $this->as($this->keeper)->getJson($this->url("/traceability/batches/{$lot->id}/journey?direction=backward"))->assertOk()->json('data.backward.nodes');
        $this->assertEqualsCanonicalizing([$a['batch']['id'], $b['batch']['id']], array_column($journey, 'id'));

        $record = $this->as($this->keeper)->getJson($this->url('/animal-production?filter[animal_id]='.$b['id']))->json('data.0');
        $this->as($this->keeper)->postJson($this->url("/animal-production/{$record['id']}/void"), ['reason' => 'Double entry'])->assertOk();
        $this->assertSame('15.000', $this->inFarm($this->farm, fn () => $lot->fresh()->quantity));
    }

    public function test_weights_groups_vaccination_and_movement(): void
    {
        $shed = $this->as($this->owner)->postJson($this->url('/structure/locations'), ['name' => 'Night kraal', 'kind' => 'housing'])->json('data.id');
        $paddock = $this->as($this->owner)->postJson($this->url('/structure/locations'), ['name' => 'Paddock 2', 'kind' => 'paddock'])->json('data.id');
        $group = $this->as($this->keeper)->postJson($this->url('/animal-groups'), ['name' => 'Milking herd', 'species_id' => $this->cattle, 'purpose' => 'dairy', 'location_id' => $shed])
            ->assertCreated()->assertJsonPath('data.code', 'GRP-001')->json('data');
        $a = $this->animal(['group_id' => $group['id']]);
        $b = $this->animal(['group_id' => $group['id']]);
        $this->assertSame($shed, $a['location']['id']);
        $this->as($this->keeper)->getJson($this->url('/animal-groups'))->assertJsonPath('data.0.head_count', 2);

        // Weights: the latest counts; a void falls back to the previous one.
        $this->as($this->keeper)->postJson($this->url('/animal-weights'), ['animal_id' => $a['id'], 'weighed_on' => now()->subDays(30)->toDateString(), 'weight_kg' => 410])->assertCreated();
        $w = $this->as($this->keeper)->postJson($this->url('/animal-weights'), ['animal_id' => $a['id'], 'weighed_on' => now()->toDateString(), 'weight_kg' => 432.5])->assertCreated()->json('data');
        $this->as($this->keeper)->getJson($this->url("/animals/{$a['id']}"))->assertJsonPath('data.last_weight_kg', 432.5);
        $this->as($this->keeper)->postJson($this->url("/animal-weights/{$w['id']}/void"), ['reason' => 'Scale not zeroed'])->assertOk();
        $this->as($this->keeper)->getJson($this->url("/animals/{$a['id']}"))->assertJsonPath('data.last_weight_kg', 410);
        $this->as($this->keeper)->postJson($this->url('/animal-weights'), ['group_id' => $group['id'], 'weighed_on' => now()->toDateString(), 'weight_kg' => 1])->assertStatus(422);

        // Group vaccination reaches every animal in the group.
        $this->as($this->keeper)->postJson($this->url('/animal-health'), [
            'group_id' => $group['id'], 'kind' => 'vaccination', 'given_on' => now()->toDateString(), 'product_name' => 'Lumpy skin disease vaccine',
            'next_due_on' => now()->addYear()->toDateString(),
        ])->assertCreated();
        foreach ([$a, $b] as $animal) {
            $this->assertContains('vaccinated', $this->events($animal['batch']['id']));
        }
        $this->as($this->keeper)->getJson($this->url('/animal-health?filter[due_before]='.now()->addYear()->addDay()->toDateString()))->assertJsonCount(1, 'data');

        // Moving the group moves its animals.
        $this->as($this->keeper)->postJson($this->url('/animal-movements'), ['group_id' => $group['id'], 'to_location_id' => $paddock, 'reason' => 'Grazing rotation'])->assertCreated();
        $this->as($this->keeper)->getJson($this->url("/animals/{$b['id']}"))->assertJsonPath('data.location.id', $paddock);
        $this->assertContains('moved', $this->events($b['batch']['id']));

        $timeline = $this->as($this->keeper)->getJson($this->url("/animals/{$a['id']}/timeline"))->assertOk()->json('data');
        $kinds = array_column($timeline, 'kind');
        $this->assertContains('movement', $kinds);
        $this->assertContains('health', $kinds);
        $this->assertNotNull(collect($timeline)->firstWhere(fn ($e) => $e['kind'] === 'weight' && $e['data']['voided'] !== null));
    }

    public function test_sales_need_owner_approval_and_respect_meat_withdrawal(): void
    {
        $steer = $this->animal(['sex' => 'male']);
        $this->as($this->keeper)->postJson($this->url('/animal-health'), ['animal_id' => $steer['id'], 'kind' => 'treatment', 'given_on' => now()->toDateString(), 'product_name' => 'Ivermectin 1%', 'meat_withdrawal_days' => 35])->assertCreated();

        $this->assertProblem($this->as($this->keeper)->postJson($this->url('/animal-sales'), ['animal_id' => $steer['id'], 'expected_price' => 1800000]), 403, 'money_field_forbidden');
        $sale = $this->as($this->keeper)->postJson($this->url('/animal-sales'), ['animal_id' => $steer['id'], 'reason' => 'Surplus bull', 'buyer' => 'Kampala butchery'])
            ->assertCreated()->assertJsonPath('data.status', 'requested')->assertJsonMissingPath('data.expected_price')->json('data');
        $this->assertProblem($this->as($this->keeper)->postJson($this->url("/animal-sales/{$sale['id']}/approve")), 403, 'forbidden');

        $this->as($this->owner)->postJson($this->url("/animal-sales/{$sale['id']}/approve"))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertProblem($this->as($this->owner)->postJson($this->url("/animal-sales/{$sale['id']}/complete"), ['sold_on' => now()->toDateString(), 'sale_price' => 1750000]), 422, 'withdrawal_period');
        $this->as($this->owner)->postJson($this->url("/animal-sales/{$sale['id']}/complete"), ['sold_on' => now()->toDateString(), 'sale_price' => 1750000, 'withdrawal_override_reason' => 'Sold live for breeding, not slaughter'])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.sale_price', 1750000);

        $shown = $this->as($this->keeper)->getJson($this->url("/animals/{$steer['id']}"))->assertJsonPath('data.status', 'sold')->json('data');
        $this->assertContains('sold', $this->events($steer['batch']['id']));
        $this->assertSame('closed', $this->inFarm($this->farm, fn () => TraceBatch::find($shown['batch']['id'])->status->value));
        $sold = $this->inFarm($this->farm, fn () => TraceEvent::where('batch_id', $shown['batch']['id'])->where('event_type', 'sold')->first()->payload);
        $this->assertArrayNotHasKey('price', $sold);
        $this->assertProblem($this->as($this->keeper)->postJson($this->url('/animal-weights'), ['animal_id' => $steer['id'], 'weighed_on' => now()->toDateString(), 'weight_kg' => 500]), 409, 'animal_inactive');
    }

    public function test_death_closes_the_animals_history(): void
    {
        $goat = $this->as($this->keeper)->postJson($this->url('/animals'), ['species_id' => DB::table('global_animal_species')->where('code', 'goat')->value('id'), 'sex' => 'female', 'origin' => 'born', 'birth_date' => now()->subYear()->toDateString()])
            ->assertCreated()->assertJsonPath('data.animal_code', 'GOA-001')->json('data');
        $this->assertSame(['created', 'born'], $this->events($goat['batch']['id']));

        $this->as($this->keeper)->postJson($this->url("/animals/{$goat['id']}/exit"), ['status' => 'dead', 'date' => now()->toDateString(), 'reason' => 'Heartwater'])
            ->assertOk()->assertJsonPath('data.status', 'dead');
        $this->assertSame(['created', 'born', 'died', 'status_changed'], $this->events($goat['batch']['id']));
        $this->as($this->keeper)->postJson($this->url("/animals/{$goat['id']}/exit"), ['status' => 'sold', 'date' => now()->toDateString(), 'reason' => 'x'])->assertStatus(422);
        $this->as($this->keeper)->getJson($this->url('/animals'))->assertJsonCount(0, 'data');
        $this->as($this->keeper)->getJson($this->url('/animals?filter[status]=dead'))->assertJsonCount(1, 'data');
    }

    public function test_livestock_dashboard(): void
    {
        $cow = $this->animal(['name' => 'Bella']);
        $heifer = $this->animal();
        $bull = $this->animal(['sex' => 'male']);
        $this->as($this->keeper)->postJson($this->url('/animal-production'), ['animal_id' => $cow['id'], 'product' => 'milk', 'produced_on' => now()->toDateString(), 'quantity' => 14, 'unit' => 'l'])->assertCreated();
        $this->as($this->keeper)->postJson($this->url('/animal-health'), ['animal_id' => $heifer['id'], 'kind' => 'vaccination', 'given_on' => now()->subYear()->toDateString(), 'product_name' => 'Brucella S19', 'next_due_on' => now()->addDays(3)->toDateString()])->assertCreated();
        $this->as($this->keeper)->postJson($this->url('/animal-health'), ['animal_id' => $cow['id'], 'kind' => 'treatment', 'given_on' => now()->toDateString(), 'product_name' => 'Penicillin', 'milk_withdrawal_days' => 4])->assertCreated();
        $b = $this->as($this->keeper)->postJson($this->url('/animal-breedings'), ['dam_id' => $heifer['id'], 'sire_id' => $bull['id'], 'method' => 'natural', 'served_on' => now()->subDays(270)->toDateString()])->json('data');
        $this->as($this->keeper)->patchJson($this->url("/animal-breedings/{$b['id']}"), ['status' => 'pregnant'])->assertOk();
        $this->as($this->keeper)->postJson($this->url('/animal-weights'), ['animal_id' => $bull['id'], 'weighed_on' => now()->subDays(20)->toDateString(), 'weight_kg' => 500])->assertCreated();
        $this->as($this->keeper)->postJson($this->url('/animal-weights'), ['animal_id' => $bull['id'], 'weighed_on' => now()->toDateString(), 'weight_kg' => 460])->assertCreated();

        $data = $this->as($this->keeper)->getJson($this->url('/dashboards/livestock?period=7d'))->assertOk()->json('data');
        $kpis = collect($data['kpis'])->keyBy('key');
        $this->assertSame(3, $kpis['livestock.head_count']['value']);
        $this->assertSame(['Cattle' => 3], $kpis['livestock.head_count']['meta']['by_species']);
        $this->assertSame(1, $kpis['livestock.pregnant']['value']);
        $this->assertSame(1, $kpis['livestock.vaccinations_due']['value']);
        $this->assertSame(1, $kpis['livestock.under_withdrawal']['value']);
        $this->assertSame(['value' => '14.0', 'unit' => 'l'], $kpis['livestock.milk']['value']);
        $this->assertSame(['value' => '-2.00', 'unit' => 'kg/day'], $kpis['livestock.daily_gain']['value']);

        $widgets = collect($data['widgets'])->keyBy('key');
        $this->assertSame('Due', $widgets['vaccinations_due']['data']['items'][0]['badge']['label']);
        $this->assertCount(1, $widgets['withdrawal_alerts']['data']['items']);
        $this->assertCount(1, $widgets['expected_births']['data']['items']);
        $this->assertStringContainsString('−8%', $widgets['weight_loss_alerts']['data']['items'][0]['subtitle']);

        $chart = $this->as($this->keeper)->getJson($this->url('/dashboards/livestock/widgets/milk_production?period=7d'))->assertOk()->json('data');
        $this->assertEquals(14, end($chart['series'][0]['values']));

        // The owner sees pending sale requests.
        $this->as($this->keeper)->postJson($this->url('/animal-sales'), ['animal_id' => $bull['id'], 'reason' => 'Culling for weight loss'])->assertCreated();
        $owner = $this->as($this->owner)->getJson($this->url('/dashboards/owner'))->json('data.widgets');
        $this->assertCount(1, collect($owner)->firstWhere('key', 'livestock_sale_requests')['data']['items']);
    }

    public function test_role_boundaries(): void
    {
        $cow = $this->animal();

        // Agronomist: no livestock (docs/04 §6 #5); livestock manager: no crops (#6).
        $agronomist = $this->memberWithRole($this->farm, 'agronomist');
        $this->assertProblem($this->as($agronomist)->getJson($this->url('/animals')), 403, 'forbidden');
        $this->assertProblem($this->as($this->keeper)->getJson($this->url('/crop-cycles')), 403, 'forbidden');

        // Accountant sees animals but cannot change records (#8).
        $accountant = $this->memberWithRole($this->farm, 'accountant');
        $this->as($accountant)->getJson($this->url('/animals'))->assertOk();
        $this->assertProblem($this->as($accountant)->postJson($this->url('/animal-weights'), ['animal_id' => $cow['id'], 'weighed_on' => now()->toDateString(), 'weight_kg' => 300]), 403, 'forbidden');

        // Field worker: assigned scope, no tasks yet.
        $worker = $this->memberWithRole($this->farm, 'field_worker');
        $this->assertProblem($this->as($worker)->postJson($this->url('/animal-feedings'), ['animal_id' => $cow['id'], 'fed_on' => now()->toDateString(), 'feed_name' => 'Hay', 'quantity' => 10, 'unit' => 'kg']), 403, 'not_assigned');
    }

    public function test_animal_records_are_append_only(): void
    {
        $cow = $this->animal();
        $this->as($this->keeper)->postJson($this->url('/animal-health'), ['animal_id' => $cow['id'], 'kind' => 'checkup', 'given_on' => now()->toDateString()])->assertCreated();

        $this->inFarm($this->farm, function () {
            $record = HealthRecord::firstOrFail();
            try {
                $record->update(['diagnosis' => 'changed']);
                $this->fail('Model update should be refused.');
            } catch (AppendOnlyViolation) {
            }
        });

        $this->expectException(QueryException::class);
        $this->inFarm($this->farm, fn () => DB::table('animal_health_records')->update(['diagnosis' => 'changed']));
    }
}
