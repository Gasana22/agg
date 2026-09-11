<?php

namespace App\Enums;

/**
 * A user's role is scoped to one farm (farm_user.role_on_farm), not global:
 * the same person can be a farm_manager on one farm and a field_worker on
 * another. Contrast with the platform-wide roles in RoleSeeder (system
 * administrator, supplier, customer), which are not farm-scoped.
 */
enum FarmRole: string
{
    case FarmOwner = 'farm_owner';
    case FarmManager = 'farm_manager';
    case Agronomist = 'agronomist';
    case LivestockManager = 'livestock_manager';
    case StoreManager = 'store_manager';
    case Accountant = 'accountant';
    case FieldWorker = 'field_worker';

    public function label(): string
    {
        return match ($this) {
            self::FarmOwner => 'Farm Owner',
            self::FarmManager => 'Farm Manager',
            self::Agronomist => 'Agronomist',
            self::LivestockManager => 'Livestock Manager',
            self::StoreManager => 'Store Manager',
            self::Accountant => 'Accountant',
            self::FieldWorker => 'Field Worker',
        };
    }
}
