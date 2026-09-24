<?php

namespace App\Modules\Livestock\Domain\Enums;

enum BreedingMethod: string
{
    case Natural = 'natural';
    case Ai = 'ai';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
