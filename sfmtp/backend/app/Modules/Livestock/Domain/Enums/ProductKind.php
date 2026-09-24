<?php

namespace App\Modules\Livestock\Domain\Enums;

enum ProductKind: string
{
    case Milk = 'milk';
    case Eggs = 'eggs';
    case Wool = 'wool';
    case Other = 'other';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
