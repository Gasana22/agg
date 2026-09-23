<?php

namespace App\Modules\FarmStructure\Domain\Enums;

enum Irrigation: string
{
    case Rainfed = 'rainfed';
    case Drip = 'drip';
    case Sprinkler = 'sprinkler';
    case Furrow = 'furrow';
    case Flood = 'flood';
    case Other = 'other';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
