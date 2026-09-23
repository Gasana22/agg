<?php

namespace App\Modules\Tenancy\Domain\Enums;

enum FarmStatus: string
{
    case Pending = 'pending';      // created, awaiting platform approval; owner can set up
    case Active = 'active';
    case Suspended = 'suspended';  // by platform admin or unpaid subscription
    case Closed = 'closed';        // archived by owner; data retained

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function allowsMemberAccess(): bool
    {
        return $this === self::Pending || $this === self::Active;
    }
}
