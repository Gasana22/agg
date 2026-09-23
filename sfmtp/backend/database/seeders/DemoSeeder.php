<?php

namespace Database\Seeders;

use App\Modules\Access\Domain\Models\FarmRole;
use App\Modules\Identity\Domain\Enums\UserType;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Platform\Application\PlatformPermissions;
use App\Modules\Tenancy\Application\FarmService;
use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Enums\LinkType;
use Illuminate\Database\Seeder;
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

    public function run(FarmService $farms, TenantContext $context, Recorder $recorder): void
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

        // A small seed-to-package journey on the crop farm.
        $context->run($crop, function () use ($recorder) {
            $seed = $recorder->createBatch(BatchKind::SeedLot, ['name' => 'Maize seed Longe 5 (lot SC-2291)', 'quantity' => '50', 'unit' => 'kg'], ['occurred_at' => now()->subDays(120)]);
            $lot = $recorder->createBatch(BatchKind::CropLot, ['name' => 'Maize — Block B / Plot B-3'], ['occurred_at' => now()->subDays(110)]);
            $recorder->link($seed, $lot, LinkType::Derived, '50', 'kg');
            $recorder->record($lot, 'inspection', ['occurred_at' => now()->subDays(60), 'latitude' => 0.3736, 'longitude' => 32.7123, 'payload' => ['note' => 'Good stand, no fall armyworm seen']]);
            $harvest = $recorder->createBatch(BatchKind::Harvest, ['name' => 'Maize harvest B-3', 'quantity' => '1020', 'unit' => 'kg'], ['occurred_at' => now()->subDays(20)]);
            $recorder->link($lot, $harvest, LinkType::Derived, '1020', 'kg');
            $dried = $recorder->createBatch(BatchKind::Processed, ['name' => 'Dried & graded maize', 'quantity' => '520', 'unit' => 'kg'], ['occurred_at' => now()->subDays(10)]);
            $recorder->link($harvest, $dried, LinkType::Split, '520', 'kg');
            $packs = $recorder->createBatch(BatchKind::Packaged, ['name' => 'Maize grain 50 kg bags ×10', 'quantity' => '500', 'unit' => 'kg'], ['occurred_at' => now()->subDays(5)]);
            $recorder->link($dried, $packs, LinkType::Package, '500', 'kg');
        });
    }
}
