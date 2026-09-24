<?php

namespace Database\Seeders;

use App\Modules\Access\Domain\Models\FarmRole;
use App\Modules\Crops\Application\CropCycles;
use App\Modules\Crops\Application\CropHarvests;
use App\Modules\Crops\Application\CropObservations;
use App\Modules\Crops\Application\CropOperations;
use App\Modules\Crops\Application\CropPlans;
use App\Modules\Crops\Application\CropSetup;
use App\Modules\Crops\Domain\Enums\CloseReason;
use App\Modules\Crops\Domain\Enums\CycleStage;
use App\Modules\Crops\Domain\Models\CropCycle;
use App\Modules\FarmStructure\Application\StructureService;
use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\FarmStructure\Domain\Models\Plot;
use App\Modules\Identity\Domain\Enums\UserType;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Livestock\Application\AnimalRecords;
use App\Modules\Livestock\Application\AnimalSales;
use App\Modules\Livestock\Application\Breedings;
use App\Modules\Livestock\Application\Herd;
use App\Modules\Livestock\Domain\Enums\AnimalStatus;
use App\Modules\Livestock\Domain\Enums\BreedingStatus;
use App\Modules\Livestock\Domain\Models\AnimalGroup;
use App\Modules\Platform\Application\PlatformPermissions;
use App\Modules\Tenancy\Application\FarmService;
use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Enums\LinkType;
use App\Modules\Workforce\Application\Activities;
use App\Modules\Workforce\Application\AttendanceBook;
use App\Modules\Workforce\Application\LeaveDesk;
use App\Modules\Workforce\Application\TaskFlow;
use App\Modules\Workforce\Application\Workers;
use App\Modules\Workforce\Domain\Enums\TaskEvent;
use App\Modules\Workforce\Domain\Enums\TaskStatus;
use App\Modules\Workforce\Domain\Models\Attendance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Demo organization "AGG Farms" (docs/10 "Seeded demo scenario").
 * Local and testing only. Every account's password is `Password123!`.
 * Owners, accountants and platform staff must enrol MFA on first login.
 * Platform staff: admin@ (super admin), support@ and billing@sfmtp.test.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Password123!';

    /** A rectangle as a GeoJSON Polygon, south-west corner at ($lng, $lat). */
    private static function rect(float $lng, float $lat, float $w, float $h): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[[$lng, $lat], [$lng + $w, $lat], [$lng + $w, $lat + $h], [$lng, $lat + $h], [$lng, $lat]]]];
    }

    public function run(FarmService $farms, TenantContext $context, Recorder $recorder, StructureService $structure): void
    {
        $user = fn (string $email, string $name, UserType $type = UserType::Member) => User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'user_type' => $type, 'password' => self::PASSWORD, 'status' => 'active', 'email_verified_at' => now()],
        );

        $platform = app(PlatformPermissions::class);
        $platform->assign($user('admin@sfmtp.test', 'Platform Admin', UserType::PlatformAdmin), 'super_admin');
        $platform->assign($user('support@sfmtp.test', 'Sam Support', UserType::PlatformAdmin), 'support');
        $platform->assign($user('billing@sfmtp.test', 'Beatrice Billing', UserType::PlatformAdmin), 'billing');
        $owner = $user('owner@aggfarms.test', 'Grace Owner');

        if (Farm::where('name', 'AGG Mixed Farm')->exists()) {
            return;
        }

        $mixed = $farms->create($owner, ['name' => 'AGG Mixed Farm', 'organization_name' => 'AGG Farms', 'district' => 'Wakiso', 'village' => 'Kakiri', 'size_ha' => 120]);
        $crop = $farms->create($owner, ['name' => 'AGG Crop Farm', 'district' => 'Mukono', 'village' => 'Seeta', 'size_ha' => 80]);

        foreach ([$mixed, $crop] as $farm) {
            $farm->forceFill(['status' => FarmStatus::Active, 'approved_at' => now()])->save();
        }

        // One member per role on the mixed farm.
        $staff = [
            'manager' => ['manager@aggfarms.test', 'Moses Manager'],
            'agronomist' => ['agronomist@aggfarms.test', 'Amina Agronomist'],
            'livestock_manager' => ['livestock@aggfarms.test', 'Lule Livestock'],
            'store_manager' => ['store@aggfarms.test', 'Sarah Store'],
            'accountant' => ['accountant@aggfarms.test', 'Alex Accountant'],
            'field_worker' => ['worker@aggfarms.test', 'Wilson Worker'],
        ];

        $context->run($mixed, function () use ($staff, $user, $mixed, $farms) {
            foreach ($staff as $roleKey => [$email, $name]) {
                $member = $farms->addMember($mixed, $user($email, $name));
                DB::table('farm_user_roles')->insert([
                    'farm_id' => $mixed->id,
                    'farm_user_id' => $member->id,
                    'farm_role_id' => FarmRole::where('key', $roleKey)->value('id'),
                    'created_at' => now(),
                ]);
            }
        });

        // Mixed farm layout near Kakiri: paddocks and livestock buildings.
        $context->run($mixed, function () use ($structure) {
            [$grazing] = $structure->create('block', ['code' => 'GRZ', 'name' => 'Grazing block', 'boundary' => self::rect(32.385, 0.400, 0.006, 0.004)]);
            [$paddocks] = $structure->create('section', ['code' => 'GRZ-PAD', 'name' => 'Paddocks', 'block_id' => $grazing->id, 'boundary' => self::rect(32.385, 0.400, 0.006, 0.004)]);
            foreach (['1', '2', '3'] as $i => $n) {
                $structure->create('plot', ['code' => "PAD-{$n}", 'name' => "Paddock {$n}", 'section_id' => $paddocks->id, 'land_use' => 'pasture',
                    'boundary' => self::rect(32.385 + $i * 0.002, 0.400, 0.002, 0.004)]);
            }
            $structure->create('location', ['code' => 'KRAAL', 'name' => 'Cattle kraal', 'kind' => 'housing', 'latitude' => 0.4045, 'longitude' => 32.3862]);
            $structure->create('location', ['code' => 'GOAT', 'name' => 'Goat house', 'kind' => 'housing', 'latitude' => 0.4047, 'longitude' => 32.3875]);
            $structure->create('location', ['code' => 'DAIRY', 'name' => 'Milking parlour', 'kind' => 'building', 'latitude' => 0.4049, 'longitude' => 32.3890]);
            $structure->create('location', ['code' => 'TANK', 'name' => 'Water tank', 'kind' => 'water', 'latitude' => 0.4043, 'longitude' => 32.3899]);
            $structure->create('location', ['code' => 'STORE', 'name' => 'Feed store', 'kind' => 'store', 'latitude' => 0.4051, 'longitude' => 32.3881]);
        });

        // Crop farm layout near Seeta: two blocks of three plots each.
        $plots = $context->run($crop, function () use ($structure) {
            $plots = [];
            foreach (['A' => 32.705, 'B' => 32.7095] as $block => $lng) {
                [$b] = $structure->create('block', ['code' => $block, 'name' => "Block {$block}", 'boundary' => self::rect($lng, 0.370, 0.004, 0.004)]);
                [$s] = $structure->create('section', ['code' => "{$block}-S1", 'name' => "Block {$block} main section", 'block_id' => $b->id, 'boundary' => self::rect($lng, 0.370, 0.004, 0.004)]);
                foreach ([1, 2, 3] as $i) {
                    [$plots["{$block}-{$i}"]] = $structure->create('plot', [
                        'code' => "{$block}-{$i}", 'name' => "Plot {$block}-{$i}", 'section_id' => $s->id,
                        'land_use' => $block === 'A' && $i === 3 ? 'forestry' : 'crop',
                        'irrigation' => $block === 'B' && $i === 1 ? 'drip' : 'rainfed',
                        'boundary' => self::rect($lng + ($i - 1) * 0.004 / 3, 0.370, 0.004 / 3, 0.004),
                    ]);
                }
            }
            $structure->recordSoil($plots['B-3'], ['texture' => 'sandy_clay_loam', 'ph' => 5.8, 'organic_matter_pct' => 2.9, 'drainage' => 'good', 'tested_on' => now()->subDays(130)->toDateString(), 'laboratory' => 'NARL Kawanda']);
            $structure->create('location', ['code' => 'STORE', 'name' => 'Grain store', 'kind' => 'store', 'latitude' => 0.3745, 'longitude' => 32.7120]);

            return $plots;
        });

        // The agronomist and a field worker also work on the crop farm.
        $context->run($crop, function () use ($crop, $farms, $user) {
            foreach (['agronomist' => 'agronomist@aggfarms.test', 'field_worker' => 'worker@aggfarms.test'] as $roleKey => $email) {
                $member = $farms->addMember($crop, $user($email, ''));
                DB::table('farm_user_roles')->insert([
                    'farm_id' => $crop->id, 'farm_user_id' => $member->id,
                    'farm_role_id' => FarmRole::where('key', $roleKey)->value('id'), 'created_at' => now(),
                ]);
            }
        });

        $this->seedCrops($crop, $plots, $context, $recorder);
        $this->seedLivestock($mixed, $context);
        $this->seedWorkforce($mixed, $crop, $context);
    }

    /**
     * A small workforce, run through the workforce services: Wilson (the
     * field worker account) and three casual workers on the mixed farm, a
     * week of attendance, today's schedule with work to verify and an overdue
     * fence, a leave request; Wilson also weeds maize on the crop farm.
     */
    private function seedWorkforce(Farm $mixed, Farm $cropFarm, TenantContext $context): void
    {
        $membership = fn (Farm $farm, string $email) => FarmUser::where('farm_id', $farm->id)->whereHas('user', fn ($q) => $q->where('email', $email))->firstOrFail();
        $as = function (Farm $farm, FarmUser $who, callable $work) use ($context) {
            Auth::setUser($who->user);

            return $context->run($farm, $work, $who);
        };
        $type = fn (string $code) => DB::table('global_activity_types')->where('code', $code)->value('id');
        $tz = $mixed->timezone;
        // A time of day, never later than `$before` minutes ago (the seeder may run early in the morning).
        $at = fn (int $daysAgo, string $time, int $before = 0) => CarbonImmutable::parse(now($tz)->subDays($daysAgo)->toDateString().' '.$time, $tz)->utc()
            ->min(CarbonImmutable::now()->subMinutes($before));

        $manager = $membership($mixed, 'manager@aggfarms.test');
        $wilsonMember = $membership($mixed, 'worker@aggfarms.test');

        // Profiles and a week of attendance (entered as check-ins from the phone).
        [$wilson, $okello, $nakato, $mugisha] = $as($mixed, $manager, function () use ($wilsonMember, $at) {
            $workers = app(Workers::class);
            $wilson = $workers->create(['full_name' => 'Wilson Worker', 'employment_type' => 'permanent', 'job_title' => 'Herdsman', 'phone' => '+256 772 100200', 'farm_user_id' => $wilsonMember->id, 'started_on' => '2024-02-01']);
            $okello = $workers->create(['full_name' => 'Okello Joseph', 'employment_type' => 'casual', 'job_title' => 'General hand', 'phone' => '+256 701 300400']);
            $nakato = $workers->create(['full_name' => 'Nakato Sarah', 'employment_type' => 'casual', 'job_title' => 'Milker']);
            $mugisha = $workers->create(['full_name' => 'Mugisha Peter', 'employment_type' => 'contract', 'job_title' => 'Fencer']);
            foreach (range(6, 1) as $d) {
                foreach ([$wilson, $okello, $nakato] as $i => $w) {
                    if ($d === 3 && $i === 2) {
                        continue;   // Nakato was off
                    }
                    if ($d === 1 && $i === 0 && now(app(TenantContext::class)->farm()->timezone)->hour < 1) {
                        continue;   // just after midnight, today's check-in below falls on yesterday
                    }
                    Attendance::create(['worker_id' => $w->id, 'work_date' => $at($d, '07:00')->setTimezone(app(TenantContext::class)->farm()->timezone)->toDateString(),
                        'check_in_at' => $at($d, sprintf('06:%02d', 50 + $i * 4)), 'check_out_at' => $at($d, sprintf('16:%02d', 10 + $i * 7)),
                        'check_in_lat' => 0.4046, 'check_in_lng' => 32.3864, 'source' => $i === 0 ? 'mobile' : 'manual', 'note' => $i === 0 ? null : 'Paper register']);
                }
            }

            return [$wilson, $okello, $nakato, $mugisha];
        });

        // The manager plans the work.
        [$vaccinate, $milking, $herding] = $as($mixed, $manager, function () use ($type, $wilson, $okello, $nakato, $mugisha) {
            $plan = app(Activities::class);
            $group = fn (string $code) => AnimalGroup::where('code', $code)->value('id');
            $plot = fn (string $code) => Plot::where('code', $code)->value('id');

            $planned = [
                $plan->create(['activity_type_id' => $type('deworming'), 'subject_type' => 'animal_group', 'subject_id' => $group('GOATS'), 'planned_on' => now()->subDay()->toDateString(),
                    'instructions' => 'Albendazole 10%, 1 ml per 10 kg. Weigh the does first.', 'worker_ids' => [$wilson->id]]),
                $plan->create(['activity_type_id' => $type('milking'), 'subject_type' => 'animal_group', 'subject_id' => $group('DAIRY'), 'title' => 'Morning and evening milking',
                    'instructions' => 'Record each cow on the milking sheet. Bella is under milk withdrawal: milk into the discard can.', 'worker_ids' => [$wilson->id, $nakato->id]]),
                $plan->create(['activity_type_id' => $type('herding'), 'subject_type' => 'animal_group', 'subject_id' => $group('BEEF'), 'title' => 'Graze the Ankole on Paddock 3',
                    'planned_on' => now()->subDay()->toDateString(), 'worker_ids' => [$okello->id]]),
                $plan->create(['activity_type_id' => $type('fencing'), 'subject_type' => 'plot', 'subject_id' => $plot('PAD-2'), 'title' => 'Repair the Paddock 2 fence',
                    'planned_on' => now()->subDays(4)->toDateString(), 'due_on' => now()->subDays(2)->toDateString(), 'priority' => 'high', 'target_quantity' => 120, 'target_unit' => 'm', 'worker_ids' => [$mugisha->id]]),
                $plan->create(['activity_type_id' => $type('cleaning_housing'), 'subject_type' => 'location', 'subject_id' => Location::where('code', 'GOAT')->value('id'),
                    'title' => 'Clean the goat house', 'worker_ids' => [$okello->id]]),
            ];

            return array_slice($planned, 0, 3);
        });

        // Wilson's work from his phone: yesterday's deworming (waiting for
        // verification), and today's milking under way after checking in.
        $as($mixed, $wilsonMember, function () use ($vaccinate, $milking, $at) {
            $flow = app(TaskFlow::class);
            $mine = fn ($activity) => $activity->tasks()->whereHas('worker', fn ($q) => $q->whereNotNull('farm_user_id'))->firstOrFail();
            $point = ['lat' => 0.4047, 'lng' => 32.3876, 'accuracy_m' => 7];
            $task = $mine($vaccinate);
            $flow->workerStep($task, TaskEvent::Start, $point + ['occurred_at' => $at(1, '09:10')]);
            $flow->workerStep($task, TaskEvent::Submit, $point + ['occurred_at' => $at(1, '10:25'), 'quantity' => 11, 'unit' => 'head', 'note' => 'All 11 goats dewormed; Mimi was not in the house.']);
            app(AttendanceBook::class)->checkIn(['occurred_at' => $at(0, '06:52', 30), 'lat' => 0.4046, 'lng' => 32.3864, 'accuracy_m' => 9]);
            $flow->workerStep($mine($milking), TaskEvent::Start, ['occurred_at' => $at(0, '07:05', 20), 'lat' => 0.4049, 'lng' => 32.3890, 'accuracy_m' => 5]);
        });

        // Okello's herding yesterday was verified (on paper, entered by the manager's team).
        $as($mixed, $manager, function () use ($herding, $at) {
            $task = $herding->tasks()->firstOrFail();
            $task->forceFill(['status' => TaskStatus::Submitted, 'started_at' => $at(1, '08:00'), 'submitted_at' => $at(1, '15:30'), 'worked_minutes' => 450])->save();
            app(TaskFlow::class)->verify($task, 'Cattle back in the kraal by 16:00');
        });

        // Nakato asks for leave next week.
        $as($mixed, $membership($mixed, 'owner@aggfarms.test'), fn () => app(LeaveDesk::class)->request([
            'worker_id' => $nakato->id, 'kind' => 'annual', 'from_on' => now()->addDays(7)->toDateString(), 'to_on' => now()->addDays(9)->toDateString(), 'reason' => 'Family visit in Masaka',
        ]));

        // On the crop farm, Wilson weeds the maize on A-1.
        $cropWilson = $membership($cropFarm, 'worker@aggfarms.test');
        $agronomist = $membership($cropFarm, 'agronomist@aggfarms.test');
        $as($cropFarm, $agronomist, function () use ($type, $cropWilson) {
            $w = app(Workers::class)->create(['full_name' => 'Wilson Worker', 'employment_type' => 'permanent', 'farm_user_id' => $cropWilson->id]);
            $cycle = CropCycle::whereHas('plot', fn ($q) => $q->where('code', 'A-1'))->where('stage', '!=', 'closed')->firstOrFail();

            return app(Activities::class)->create(['activity_type_id' => $type('weeding'), 'subject_type' => 'crop_cycle', 'subject_id' => $cycle->id,
                'instructions' => 'Second weeding; watch for fall armyworm and report it.', 'target_quantity' => 2, 'target_unit' => 'ha', 'worker_ids' => [$w->id]]);
        });
    }

    /**
     * Two seasons of crop work on the crop farm, recorded through the crop
     * services so that the traceability history is the real one: a maize
     * cycle from seed lot to packaged grain, and the current season's crops.
     */
    private function seedCrops(Farm $farm, array $plots, TenantContext $context, Recorder $recorder): void
    {
        $membership = fn (string $email) => FarmUser::where('farm_id', $farm->id)->whereHas('user', fn ($q) => $q->where('email', $email))->firstOrFail();
        $agronomist = $membership('agronomist@aggfarms.test');
        $owner = $membership('owner@aggfarms.test');
        $as = function (FarmUser $who, callable $work) use ($context, $farm) {
            Auth::setUser($who->user);

            return $context->run($farm, $work, $who);
        };
        $day = fn (int $daysAgo) => now()->subDays($daysAgo);
        $variety = fn (string $code) => DB::table('global_crop_varieties')->where('code', $code)->value('id');

        [$maize, $beans, $cabbage, $seasonA, $seasonB, $planA, $planB, $seed, $beanSeed] = $as($agronomist, function () use ($day, $variety, $recorder) {
            $setup = app(CropSetup::class);
            $maize = $setup->addCrop(['global_variety_id' => $variety('longe_5')]);
            $beans = $setup->addCrop(['global_variety_id' => $variety('nabe_15')]);
            $cabbage = $setup->addCrop(['global_crop_id' => DB::table('global_crops')->where('code', 'cabbage')->value('id'), 'variety' => 'Gloria F1', 'maturity_days' => 90, 'yield_unit' => 'pcs']);
            $year = now()->year;
            $seasonA = $setup->addSeason(['name' => "{$year} Season A", 'starts_on' => $day(200)->toDateString(), 'ends_on' => $day(60)->toDateString()]);
            $seasonB = $setup->addSeason(['name' => "{$year} Season B", 'starts_on' => $day(59)->toDateString(), 'ends_on' => now()->addDays(100)->toDateString()]);
            $plans = app(CropPlans::class);
            $planA = $plans->create(['name' => 'Maize, Block B', 'season_id' => $seasonA->id, 'crop_id' => $maize->id, 'planned_area_ha' => 6.6, 'expected_yield' => 3000]);
            $planB = $plans->create(['name' => 'Maize, Block A', 'season_id' => $seasonB->id, 'crop_id' => $maize->id, 'planned_area_ha' => 13.2, 'expected_yield' => 6000]);
            $seed = $recorder->createBatch(BatchKind::SeedLot, ['name' => 'Maize seed Longe 5 (lot SC-2291)', 'quantity' => '50', 'unit' => 'kg'], ['occurred_at' => $day(120)]);
            $beanSeed = $recorder->createBatch(BatchKind::SeedLot, ['name' => 'Bean seed NABE 15 (lot NB-0716)', 'quantity' => '60', 'unit' => 'kg'], ['occurred_at' => $day(25)]);

            return [$maize, $beans, $cabbage, $seasonA, $seasonB, $planA, $planB, $seed, $beanSeed];
        });
        $as($owner, function () use ($planA, $planB) {
            app(CropPlans::class)->approve($planA);
            app(CropPlans::class)->approve($planB);
        });

        $as($agronomist, function () use ($plots, $maize, $beans, $cabbage, $planA, $planB, $seed, $beanSeed, $day, $recorder) {
            $cycles = app(CropCycles::class);
            $ops = app(CropOperations::class);
            $obs = app(CropObservations::class);
            $harvests = app(CropHarvests::class);

            // Season A: maize on B-3, sown to packed.
            [$b3] = $cycles->start(['plot_id' => $plots['B-3']->id, 'crop_id' => $maize->id, 'plan_id' => $planA->id, 'seed_batch_id' => $seed->id, 'planted_on' => $day(110)->toDateString(), 'expected_yield' => 1000]);
            $ops->record($b3, ['type' => 'fertilizing', 'occurred_at' => $day(110), 'notes' => 'Basal DAP at planting',
                'inputs' => [['product_name' => 'DAP 18-46-0', 'quantity' => 125, 'unit' => 'kg']]]);
            $ops->record($b3, ['type' => 'weeding', 'occurred_at' => $day(85), 'labour_hours' => 48]);
            $armyworm = $obs->report($b3, ['kind' => 'pest', 'severity' => 'medium', 'title' => 'Fall armyworm', 'affected_pct' => 8, 'observed_at' => $day(56), 'latitude' => 0.3736, 'longitude' => 32.7123]);
            $ops->record($b3, ['type' => 'spraying', 'occurred_at' => $day(55), 'observation_id' => $armyworm->id,
                'inputs' => [['product_name' => 'Emamectin benzoate 5% SG', 'quantity' => 0.4, 'unit' => 'kg', 'withholding_days' => 14]]]);
            $obs->update($armyworm, ['status' => 'resolved', 'resolution_note' => 'No live larvae a week after spraying']);
            $ops->record($b3, ['type' => 'fertilizing', 'occurred_at' => $day(70), 'notes' => 'Top dressing', 'inputs' => [['product_name' => 'Urea 46% N', 'quantity' => 100, 'unit' => 'kg']]]);
            $harvest = $harvests->record($b3->refresh(), ['harvested_on' => $day(20)->toDateString(), 'quantity' => 1020, 'unit' => 'kg', 'quality_grade' => 'A', 'moisture_pct' => 17.5]);
            $cycles->close($b3->refresh(), CloseReason::Harvested, 'Stover left as mulch', $day(18)->toDateString());

            $dried = $recorder->createBatch(BatchKind::Processed, ['name' => 'Dried & graded maize', 'quantity' => '520', 'unit' => 'kg'], ['occurred_at' => $day(10)]);
            $recorder->link($harvest->batch, $dried, LinkType::Split, '520', 'kg');
            $packs = $recorder->createBatch(BatchKind::Packaged, ['name' => 'Maize grain 50 kg bags ×10', 'quantity' => '500', 'unit' => 'kg'], ['occurred_at' => $day(5)]);
            $recorder->link($dried, $packs, LinkType::Package, '500', 'kg');

            // Season B, in progress.
            [$a1] = $cycles->start(['plot_id' => $plots['A-1']->id, 'crop_id' => $maize->id, 'plan_id' => $planB->id, 'seed_batch_id' => $seed->id, 'planted_on' => $day(40)->toDateString(), 'expected_yield' => 3000]);
            $cycles->advance($a1, CycleStage::Growing);
            $ops->record($a1, ['type' => 'weeding', 'occurred_at' => $day(12), 'labour_hours' => 40]);
            $obs->report($a1, ['kind' => 'pest', 'severity' => 'high', 'title' => 'Fall armyworm', 'description' => 'Window-pane damage on young leaves; larvae in the whorls.', 'affected_pct' => 20, 'observed_at' => $day(1)]);

            [$b2] = $cycles->start(['plot_id' => $plots['B-2']->id, 'crop_id' => $maize->id, 'plan_id' => $planA->id, 'seed_batch_id' => $seed->id, 'planted_on' => $day(105)->toDateString(), 'expected_yield' => 950]);
            $cycles->advance($b2, CycleStage::Growing);
            $leafBlight = $obs->report($b2, ['kind' => 'disease', 'severity' => 'medium', 'title' => 'Northern leaf blight', 'affected_pct' => 10, 'observed_at' => $day(8)]);
            $ops->record($b2, ['type' => 'spraying', 'occurred_at' => $day(6), 'observation_id' => $leafBlight->id,
                'inputs' => [['product_name' => 'Mancozeb 80% WP', 'quantity' => 2.5, 'unit' => 'kg', 'withholding_days' => 21]]]);

            $cycles->start(['plot_id' => $plots['A-2']->id, 'crop_id' => $beans->id, 'seed_batch_id' => $beanSeed->id, 'planted_on' => $day(20)->toDateString(), 'expected_yield' => 1200]);

            $cycles->start(['plot_id' => $plots['B-1']->id, 'crop_id' => $cabbage->id, 'planting_method' => 'transplant', 'sown_on' => $day(21)->toDateString(), 'seeds_sown' => 30000, 'expected_yield' => 25000, 'area_ha' => 1.5]);
        });

        Auth::forgetGuards();
    }

    /**
     * The mixed farm's animals, recorded through the livestock services: a
     * Friesian dairy herd with daily milking, an Ankole beef herd, a goat
     * flock and a layer flock, with treatments, vaccinations, breeding,
     * a birth, a death and a pending sale request.
     */
    private function seedLivestock(Farm $farm, TenantContext $context): void
    {
        $keeper = FarmUser::where('farm_id', $farm->id)->whereHas('user', fn ($q) => $q->where('email', 'livestock@aggfarms.test'))->firstOrFail();
        Auth::setUser($keeper->user);

        $context->run($farm, function () {
            $herd = app(Herd::class);
            $records = app(AnimalRecords::class);
            $breedings = app(Breedings::class);
            $species = fn (string $code) => DB::table('global_animal_species')->where('code', $code)->value('id');
            $breed = fn (string $code) => DB::table('global_animal_breeds')->where('code', $code)->value('id');
            $location = fn (string $code) => Location::where('code', $code)->value('id');
            $day = fn (int $daysAgo) => now()->subDays($daysAgo)->toDateString();

            $dairy = $herd->createGroup(['code' => 'DAIRY', 'name' => 'Dairy herd', 'species_id' => $species('cattle'), 'purpose' => 'dairy', 'location_id' => $location('KRAAL')]);
            $beef = $herd->createGroup(['code' => 'BEEF', 'name' => 'Ankole herd', 'species_id' => $species('cattle'), 'purpose' => 'beef', 'location_id' => $location('KRAAL')]);
            $goats = $herd->createGroup(['code' => 'GOATS', 'name' => 'Boer goats', 'species_id' => $species('goat'), 'purpose' => 'meat', 'location_id' => $location('GOAT')]);
            $herd->createGroup(['code' => 'LAYERS', 'name' => 'Layer flock', 'species_id' => $species('chicken'), 'purpose' => 'layers', 'flock_size' => 250]);

            $cow = fn (string $name, string $tag, int $ageDays) => $herd->register([
                'species_id' => $species('cattle'), 'breed_id' => $breed('friesian'), 'sex' => 'female', 'name' => $name, 'tag_number' => $tag,
                'origin' => 'purchased', 'birth_date' => $day($ageDays), 'birth_date_estimated' => true, 'acquired_on' => $day(400), 'group_id' => $dairy->id,
            ]);
            $bella = $cow('Bella', 'UG-WAK-1001', 1900);
            $neema = $cow('Neema', 'UG-WAK-1002', 1600);
            $amani = $cow('Amani', 'UG-WAK-1003', 1300);
            $bull = $herd->register(['species_id' => $species('cattle'), 'breed_id' => $breed('friesian'), 'sex' => 'male', 'name' => 'Kato', 'tag_number' => 'UG-WAK-2001', 'origin' => 'purchased', 'birth_date' => $day(1500), 'acquired_on' => $day(400), 'group_id' => $dairy->id]);
            $steers = [];
            foreach (['UG-WAK-3001', 'UG-WAK-3002', 'UG-WAK-3003'] as $i => $tag) {
                $steers[] = $herd->register(['species_id' => $species('cattle'), 'breed_id' => $breed('ankole'), 'sex' => 'male', 'tag_number' => $tag, 'origin' => 'purchased', 'birth_date' => $day(800 + 30 * $i), 'acquired_on' => $day(300), 'group_id' => $beef->id]);
            }
            $does = [];
            foreach (['Kiki', 'Lulu', 'Mimi', 'Nana'] as $i => $name) {
                $does[] = $herd->register(['species_id' => $species('goat'), 'breed_id' => $breed('boer'), 'sex' => $i === 3 ? 'male' : 'female', 'name' => $name, 'origin' => 'purchased', 'birth_date' => $day(700 + 20 * $i), 'acquired_on' => $day(250), 'group_id' => $goats->id]);
            }

            // Bella calved 20 days ago; Amani is due in about a week.
            $served = $breedings->serve(['dam_id' => $bella->id, 'sire_id' => $bull->id, 'method' => 'natural', 'served_on' => $day(303)]);
            $breedings->update($served, BreedingStatus::Pregnant, $day(250), 'Confirmed by rectal palpation');
            $breedings->birth($served, $day(20), [['sex' => 'female', 'name' => 'Bella II', 'tag_number' => 'UG-WAK-1004']], 'Easy calving');
            $pregnant = $breedings->serve(['dam_id' => $amani->id, 'method' => 'ai', 'sire_note' => 'Friesian straw, NAGRC lot 4471', 'served_on' => $day(276)]);
            $breedings->update($pregnant, BreedingStatus::Pregnant, $day(220), null);

            // Health: a herd vaccination falling due, goat deworming, and mastitis on Neema.
            $records->health(['group_id' => $dairy->id, 'kind' => 'vaccination', 'given_on' => $day(360), 'product_name' => 'Lumpy skin disease vaccine', 'given_by' => 'Dr. Okello (district vet)', 'next_due_on' => now()->addDays(5)->toDateString()]);
            $records->health(['group_id' => $beef->id, 'kind' => 'vaccination', 'given_on' => $day(200), 'product_name' => 'FMD vaccine', 'next_due_on' => now()->addDays(165)->toDateString()]);
            $records->health(['group_id' => $goats->id, 'kind' => 'deworming', 'given_on' => $day(95), 'product_name' => 'Albendazole 10%', 'dose' => 5, 'dose_unit' => 'ml', 'meat_withdrawal_days' => 14, 'next_due_on' => $day(5)]);
            $records->health(['animal_id' => $neema->id, 'kind' => 'treatment', 'given_on' => $day(2), 'diagnosis' => 'Clinical mastitis, rear left quarter', 'product_name' => 'Cloxacillin intramammary', 'dose' => 1, 'dose_unit' => 'pcs', 'meat_withdrawal_days' => 7, 'milk_withdrawal_days' => 5, 'given_by' => 'Lule Livestock']);

            // Milk: two sessions a day for two weeks; Neema's milk discarded under withdrawal.
            foreach (range(13, 0) as $ago) {
                foreach (['am' => 1.0, 'pm' => 0.8] as $session => $share) {
                    foreach ([[$bella, 11.0], [$neema, 9.5], [$amani, 7.0]] as [$animal, $litres]) {
                        if ($animal->is($amani) && $ago < 10) {
                            continue;   // dried off before calving
                        }
                        $records->production(['animal_id' => $animal->id, 'product' => 'milk', 'produced_on' => $day($ago), 'session' => $session,
                            'quantity' => round($litres * $share + (($ago * 7) % 5) * 0.2, 1), 'unit' => 'l', 'discarded' => $animal->is($neema) && $ago <= 2]);
                    }
                }
                $records->production(['group_id' => AnimalGroup::where('code', 'LAYERS')->value('id'), 'product' => 'eggs', 'produced_on' => $day($ago), 'session' => 'day', 'quantity' => 205 + ($ago % 4) * 6, 'unit' => 'pcs']);
            }

            // Weights: steers gaining; one goat losing weight.
            foreach ($steers as $i => $steer) {
                foreach ([90 => 238, 45 => 262, 3 => 285] as $ago => $kg) {
                    $records->weight(['animal_id' => $steer->id, 'weighed_on' => $day($ago), 'weight_kg' => $kg + 11 * $i]);
                }
            }
            $records->weight(['animal_id' => $does[1]->id, 'weighed_on' => $day(40), 'weight_kg' => 42]);
            $records->weight(['animal_id' => $does[1]->id, 'weighed_on' => $day(4), 'weight_kg' => 37.5, 'notes' => 'Thin, check for worms']);
            $records->feeding(['group_id' => $dairy->id, 'fed_on' => $day(1), 'feed_name' => 'Dairy meal', 'quantity' => 24, 'unit' => 'kg']);
            $records->move(['group_id' => $beef->id, 'to_location_id' => Location::where('code', 'KRAAL')->value('id'), 'reason' => 'Night housing', 'moved_at' => now()->subDays(1)->setTime(18, 30)]);

            $herd->exit($does[2], AnimalStatus::Dead, $day(12), 'Pneumonia after heavy rains');
            app(AnimalSales::class)->request(['animal_id' => $steers[2]->id, 'reason' => 'Finished weight reached', 'buyer' => 'Wakiso livestock market']);
        }, $keeper);

        Auth::forgetGuards();
    }
}
