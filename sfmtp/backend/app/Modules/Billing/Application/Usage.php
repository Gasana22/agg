<?php

namespace App\Modules\Billing\Application;

use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use Illuminate\Support\Facades\DB;

/** What an organization uses, measured against its plan's limits. */
class Usage
{
    public function farms(string $organizationId): int
    {
        return DB::table('farms')
            ->where('organization_id', $organizationId)
            ->where('status', '!=', FarmStatus::Closed->value)
            ->count();
    }

    /** Distinct people with an active membership in any of the organization's open farms. */
    public function users(string $organizationId): int
    {
        return $this->userQuery($organizationId)->distinct()->count('farm_users.user_id');
    }

    public function isCountedUser(string $organizationId, string $userId): bool
    {
        return $this->userQuery($organizationId)->where('farm_users.user_id', $userId)->exists();
    }

    /** @return array{farms:array{used:int,limit:?int}, users:array{used:int,limit:?int}, storage_mb:array{used:?int,limit:?int}} */
    public function summary(string $organizationId, ?int $maxFarms, ?int $maxUsers, ?int $maxStorageMb): array
    {
        return [
            'farms' => ['used' => $this->farms($organizationId), 'limit' => $maxFarms],
            'users' => ['used' => $this->users($organizationId), 'limit' => $maxUsers],
            // Measured once media uploads exist (Phase 6).
            'storage_mb' => ['used' => null, 'limit' => $maxStorageMb],
        ];
    }

    private function userQuery(string $organizationId)
    {
        return DB::table('farm_users')
            ->join('farms', 'farms.id', '=', 'farm_users.farm_id')
            ->where('farms.organization_id', $organizationId)
            ->where('farms.status', '!=', FarmStatus::Closed->value)
            ->where('farm_users.status', 'active');
    }
}
