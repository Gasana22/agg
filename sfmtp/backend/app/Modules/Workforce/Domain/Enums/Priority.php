<?php

namespace App\Modules\Workforce\Domain\Enums;

/** Task priority, shown on the worker's list. */
enum Priority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
