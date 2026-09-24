<?php

namespace App\Modules\Livestock\Domain\Enums;

enum AnimalStatus: string
{
    case Active = 'active';
    case Sold = 'sold';
    case Dead = 'dead';
    case Culled = 'culled';
    case Transferred = 'transferred';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
