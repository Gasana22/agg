<?php

namespace App\Modules\Livestock\Domain\Enums;

enum Sex: string
{
    case Female = 'female';
    case Male = 'male';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
