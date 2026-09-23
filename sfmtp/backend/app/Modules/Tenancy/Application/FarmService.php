<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Identity\Domain\Enums\UserType;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\Domain\Enums\MembershipStatus;
use App\Modules\Tenancy\Domain\Events\FarmCreated;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FarmService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Create a farm owned by $owner. The farm starts `pending` until a
     * platform admin approves it (Phase 2); the owner can set it up meanwhile.
     */
    public function create(User $owner, array $attributes): Farm
    {
        if ($owner->user_type !== UserType::Member) {
            throw ApiException::forbidden('member_account_required', 'Only farm member accounts can create farms.');
        }

        return DB::transaction(function () use ($owner, $attributes) {
            $organization = Organization::firstOrCreate(
                ['owner_user_id' => $owner->id],
                ['name' => $attributes['organization_name'] ?? $owner->name, 'status' => 'active'],
            );

            $farm = Farm::create([
                'organization_id' => $organization->id,
                'code' => $this->generateCode($attributes['name']),
                'status' => FarmStatus::Pending,
            ] + array_intersect_key($attributes, array_flip([
                'name', 'district', 'village', 'country', 'size_ha', 'timezone', 'currency',
            ])));

            $membership = FarmUser::create([
                'farm_id' => $farm->id,
                'user_id' => $owner->id,
                'status' => MembershipStatus::Active,
                'is_owner' => true,
                'joined_at' => now(),
            ]);

            $this->context->run($farm, function () use ($farm, $membership) {
                DB::table('farm_settings')->insert([
                    'farm_id' => $farm->id,
                    'settings' => json_encode(self::defaultSettings()),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                FarmCreated::dispatch($farm, $membership);
            }, $membership);

            return $farm->refresh();
        });
    }

    public function update(Farm $farm, array $attributes): Farm
    {
        $farm->fill(array_intersect_key($attributes, array_flip([
            'name', 'district', 'village', 'country', 'size_ha', 'timezone', 'currency',
        ])))->save();

        return $farm;
    }

    /** Archive the farm. Data is retained (docs/07 §3 retention). */
    public function close(Farm $farm): void
    {
        $farm->forceFill(['status' => FarmStatus::Closed, 'closed_at' => now()])->save();
    }

    /** Farm-level policy defaults; owners change them in Phase 3. */
    public static function defaultSettings(): array
    {
        return [
            'require_mfa_for_all' => false,
            'approval_thresholds' => ['expense' => null, 'purchase_order' => null, 'stock_adjustment_pct' => null],
            'allow_negative_stock' => false,
            'units' => 'metric',
        ];
    }

    private function generateCode(string $name): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', Str::ascii($name)) ?: 'FARM', 0, 3));
        $prefix = str_pad($prefix, 3, 'X');

        do {
            $code = $prefix.'-'.random_int(1000, 9999);
        } while (Farm::where('code', $code)->exists());

        return $code;
    }
}
