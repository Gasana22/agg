<?php

namespace App\Modules\Identity\Domain\Enums;

/**
 * Decides which API surfaces a user can reach at all (docs/01 §6).
 * A member reaches a farm only through a farm_users membership.
 */
enum UserType: string
{
    case PlatformAdmin = 'platform_admin';
    case Member = 'member';
    case Party = 'party';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
