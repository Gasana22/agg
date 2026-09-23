<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
